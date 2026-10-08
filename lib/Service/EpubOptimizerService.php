<?php
namespace OCA\KoreaderCompanion\Service;

use Psr\Log\LoggerInterface;

/**
 * Shrinks oversized raster images inside an EPUB.
 *
 * Ported from crosspoint-reader's browser-based optimizer, minus what only
 * makes sense for their own fixed-resolution e-ink firmware: no hardcoded
 * 480x800 target (width/height/grayscale are caller-supplied), no image
 * splitting (that pages across their screen, not relevant here), and no
 * embedded-font stripping (real EPUB readers render fonts natively).
 *
 * Like crosspoint, PNGs are converted to baseline JPEG (flattened on white) and
 * every reference (OPF manifest href + media-type, XHTML/CSS/NCX links) is
 * rewritten to the new name. E-ink readers often cannot render PNGs (palette
 * or RGBA) at all, so leaving them as PNG makes the images disappear. JPEGs
 * stay JPEG. GIFs are left untouched since GD only decodes the first frame
 * of an animated one.
 */
class EpubOptimizerService {

    /** Extensions this service will resize/re-encode. Anything else passes through untouched. */
    private const RASTER_EXTENSIONS = ['jpg', 'jpeg'];

    /** Text entries whose links to converted PNGs must be rewritten. */
    private const REWRITE_EXTENSIONS = ['opf', 'xhtml', 'html', 'htm', 'xml', 'css', 'ncx', 'svg'];

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array{max_width:int,max_height:int,grayscale:bool,quality:int} $settings
     * @throws \RuntimeException if the epub cannot be opened or rewritten at all -- callers
     *         should catch this and fall back to storing the original bytes unmodified.
     */
    public function optimize(string $epubBytes, array $settings): string {
        $sourcePath = tempnam(sys_get_temp_dir(), 'epub_opt_src_');
        $targetPath = tempnam(sys_get_temp_dir(), 'epub_opt_out_');

        try {
            file_put_contents($sourcePath, $epubBytes);

            $source = new \ZipArchive();
            if ($source->open($sourcePath) !== true) {
                throw new \RuntimeException('Could not open epub as a zip archive');
            }

            // Write to a second archive rather than mutating the source in place --
            // ZipArchive does not reliably support replacing an entry's content
            // while iterating the same archive across all supported versions.
            unlink($targetPath);
            $target = new \ZipArchive();
            if ($target->open($targetPath, \ZipArchive::CREATE) !== true) {
                $source->close();
                throw new \RuntimeException('Could not create output zip archive');
            }

            // The EPUB spec (OCF) wants `mimetype` first and stored uncompressed so
            // readers can sniff it; addFromString() would deflate it and keep zip order.
            $mimetypeIndex = $source->locateName('mimetype');
            if ($mimetypeIndex !== false) {
                $mimetype = $source->getFromIndex($mimetypeIndex);
                if ($mimetype !== false) {
                    $target->addFromString('mimetype', $mimetype);
                    $target->setCompressionName('mimetype', \ZipArchive::CM_STORE);
                }
            }

            // PNG => JPEG conversions that actually succeeded: old name => [new name, bytes].
            $converted = $this->convertPngs($source, $settings);
            $renames = array_map(static fn(array $c): string => $c[0], $converted);

            for ($i = 0; $i < $source->numFiles; $i++) {
                $name = $source->getNameIndex($i);
                // Skip bare directory entries (pure overhead) and the already-written mimetype.
                if ($name === false || $name === 'mimetype' || str_ends_with($name, '/')) {
                    continue;
                }

                if (isset($converted[$name])) {
                    [$name, $data] = $converted[$name];
                    $target->addFromString($name, $data);
                    $target->setCompressionName($name, \ZipArchive::CM_STORE);
                    continue;
                }

                $data = $source->getFromIndex($i);
                if ($data === false) {
                    continue;
                }

                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (in_array($extension, self::RASTER_EXTENSIONS, true)) {
                    $data = $this->optimizeImage($data, $extension, $settings, $name);
                } elseif ($renames !== [] && in_array($extension, self::REWRITE_EXTENSIONS, true)) {
                    $data = $this->rewriteReferences($data, $extension, $renames);
                }

                $target->addFromString($name, $data);
                if (method_exists($target, 'setCompressionName')) {
                    $target->setCompressionName($name, \ZipArchive::CM_DEFLATE, 9);
                }
            }

            $source->close();
            $target->close();

            $result = file_get_contents($targetPath);
            if ($result === false || $result === '') {
                throw new \RuntimeException('Could not read back optimized epub');
            }
            return $result;
        } finally {
            @unlink($sourcePath);
            @unlink($targetPath);
        }
    }

    /**
     * @param array{max_width:int,max_height:int,grayscale:bool,quality:int} $settings
     */
    private function optimizeImage(string $data, string $extension, array $settings, string $name): string {
        try {
            $image = @imagecreatefromstring($data);
            if ($image === false) {
                return $data;
            }

            $width = imagesx($image);
            $height = imagesy($image);
            if ($width <= 0 || $height <= 0) {
                imagedestroy($image);
                return $data;
            }

            $maxWidth = max(1, (int)$settings['max_width']);
            $maxHeight = max(1, (int)$settings['max_height']);
            $grayscale = !empty($settings['grayscale']);
            $scale = min(1.0, $maxWidth / $width, $maxHeight / $height);

            if ($scale >= 1.0 && !$grayscale && $extension !== 'png') {
                // Already within bounds and no grayscale requested -- nothing to do.
                imagedestroy($image);
                return $data;
            }

            $newWidth = max(1, (int)round($width * $scale));
            $newHeight = max(1, (int)round($height * $scale));

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            // Flatten transparency on white: JPEG has no alpha.
            $white = imagecolorallocate($resized, 255, 255, 255);
            imagefill($resized, 0, 0, $white);

            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);

            if ($grayscale) {
                imagefilter($resized, IMG_FILTER_GRAYSCALE);
            }

            ob_start();
            imageinterlace($resized, false); // baseline JPEG: progressive is poorly supported on e-ink
            imagejpeg($resized, null, max(1, min(100, (int)$settings['quality'])));
            $encoded = ob_get_clean();
            imagedestroy($resized);

            // Never make the book bigger: re-encoding an already well-compressed image
            // (small logos, tuned JPEGs) can grow it.
            if ($encoded === false || $encoded === '') {
                return $data;
            }
            // A PNG must become a JPEG regardless of size; a JPEG is only replaced if smaller.
            return ($extension === 'png' || strlen($encoded) < strlen($data)) ? $encoded : $data;
        } catch (\Throwable $e) {
            $this->logger->debug('Skipping one image during epub optimization', [
                'app' => 'koreader_companion',
                'entry' => $name,
                'exception' => $e,
            ]);
            return $data;
        }
    }

    /**
     * Converts every PNG in the archive to JPEG. Returns only conversions that
     * succeeded, so references are never rewritten for an image left as PNG.
     *
     * @return array<string, array{0:string,1:string}> old entry name => [new entry name, JPEG bytes]
     */
    private function convertPngs(\ZipArchive $source, array $settings): array {
        $taken = [];
        for ($i = 0; $i < $source->numFiles; $i++) {
            $taken[strtolower((string)$source->getNameIndex($i))] = true;
        }

        $converted = [];
        for ($i = 0; $i < $source->numFiles; $i++) {
            $name = $source->getNameIndex($i);
            if ($name === false || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'png') {
                continue;
            }
            $data = $source->getFromIndex($i);
            if ($data === false) {
                continue;
            }
            $jpeg = $this->optimizeImage($data, 'png', $settings, $name);
            if (!str_starts_with($jpeg, "\xFF\xD8")) {
                continue;
            }
            $newName = substr($name, 0, -3) . 'jpg';
            for ($n = 2; isset($taken[strtolower($newName)]); $n++) {
                $newName = substr($name, 0, -4) . "-$n.jpg";
            }
            $taken[strtolower($newName)] = true;
            $converted[$name] = [$newName, $jpeg];
        }
        return $converted;
    }

    /**
     * Points links at converted images. Entry names are relative to the zip root
     * while links are relative to the referencing file, so match on the path's
     * tail and only where it starts a URL (after a quote, slash, paren or `=`).
     *
     * @param array<string, string> $renames old entry name => new entry name
     */
    private function rewriteReferences(string $data, string $extension, array $renames): string {
        $text = $data;
        foreach ($renames as $old => $new) {
            $oldBase = basename($old);
            $newBase = basename($new);
            $quoted = preg_quote($oldBase, '~');
            $pattern = '~(["\'(/=])((?:[^"\'()<>\s]*/)?)' . $quoted . '(?![\w.-])~i';
            $text = preg_replace($pattern, '${1}${2}' . addcslashes($newBase, '\\$'), $text) ?? $text;
        }
        if ($extension === 'opf') {
            // Fix the manifest media-type of items that now point at a .jpg.
            $text = preg_replace_callback('~<item\b[^>]*>~i', static function (array $m): string {
                $tag = $m[0];
                if (preg_match('~href="[^"]*\.jpg"~i', $tag)) {
                    $tag = preg_replace('~media-type="image/png"~i', 'media-type="image/jpeg"', $tag);
                }
                return $tag;
            }, $text) ?? $text;
        }
        return $text;
    }
}
