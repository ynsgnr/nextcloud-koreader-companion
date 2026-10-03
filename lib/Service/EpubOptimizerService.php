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
 * Also keeps the original format per image (JPEG stays JPEG, PNG stays PNG)
 * instead of always re-encoding to JPEG like crosspoint does -- otherwise the
 * bytes would stop matching the OPF manifest's declared media-type. GIFs are
 * left untouched since GD only decodes the first frame of an animated one.
 */
class EpubOptimizerService {

    /** Extensions this service will resize/re-encode. Anything else passes through untouched. */
    private const RASTER_EXTENSIONS = ['jpg', 'jpeg', 'png'];

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

            for ($i = 0; $i < $source->numFiles; $i++) {
                $name = $source->getNameIndex($i);
                if ($name === false) {
                    continue;
                }

                $data = $source->getFromIndex($i);
                if ($data === false) {
                    continue;
                }

                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (in_array($extension, self::RASTER_EXTENSIONS, true)) {
                    $data = $this->optimizeImage($data, $extension, $settings, $name);
                }

                $target->addFromString($name, $data);
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

            if ($scale >= 1.0 && !$grayscale) {
                // Already within bounds and no grayscale requested -- nothing to do.
                imagedestroy($image);
                return $data;
            }

            $newWidth = max(1, (int)round($width * $scale));
            $newHeight = max(1, (int)round($height * $scale));

            $resized = imagecreatetruecolor($newWidth, $newHeight);

            if ($extension === 'png') {
                // Preserve transparency rather than flattening to white -- unlike
                // crosspoint, which always outputs JPEG and so has no alpha to keep.
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
                imagefill($resized, 0, 0, $transparent);
            } else {
                $white = imagecolorallocate($resized, 255, 255, 255);
                imagefill($resized, 0, 0, $white);
            }

            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);

            if ($grayscale) {
                imagefilter($resized, IMG_FILTER_GRAYSCALE);
            }

            ob_start();
            if ($extension === 'png') {
                imagepng($resized, null, 6);
            } else {
                imagejpeg($resized, null, max(1, min(100, (int)$settings['quality'])));
            }
            $encoded = ob_get_clean();
            imagedestroy($resized);

            return ($encoded !== false && $encoded !== '') ? $encoded : $data;
        } catch (\Throwable $e) {
            $this->logger->debug('Skipping one image during epub optimization', [
                'app' => 'koreader_companion',
                'entry' => $name,
                'exception' => $e,
            ]);
            return $data;
        }
    }
}
