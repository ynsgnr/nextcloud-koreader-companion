<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\Service\Article;

use OCA\KoreaderCompanion\AppInfo\Application;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Downloads an article image and returns it in a format every e-reader can show.
 *
 * Requests go through Nextcloud's HTTP client, which refuses local and private
 * addresses unless the admin allows them.
 */
class ImageDownloader {

    private const TIMEOUT_SECONDS = 10;
    private const MAX_DOWNLOAD_BYTES = 16 * 1024 * 1024;
    private const MIN_PIXELS = 100;
    private const MAX_PIXELS = 40_000_000;
    private const JPEG_QUALITY = 85;

    private const KEPT_AS_IS_TYPES = ['image/jpeg', 'image/png'];
    private const CONVERTED_TYPES = ['image/gif', 'image/webp'];

    private const WEBP_FRAME_HEADER_BYTES = 16;

    public function __construct(
        private IClientService $clientService,
        private LoggerInterface $logger,
    ) {
    }

    /** Null when the image is unreachable, unsupported, or unreasonably small or large. */
    public function download(string $url): ?EmbeddedImage {
        try {
            $bytes = (string)$this->clientService->newClient()->get($url, [
                'timeout' => self::TIMEOUT_SECONDS,
                'headers' => ['Accept' => 'image/jpeg,image/png,image/webp,image/gif'],
            ])->getBody();

            return strlen($bytes) > self::MAX_DOWNLOAD_BYTES ? null : $this->prepare($bytes);
        } catch (\Throwable $e) {
            $this->logger->debug('Skipping an article image', [
                'app' => Application::APP_ID,
                'url' => $url,
                'exception' => $e,
            ]);

            return null;
        }
    }

    private function prepare(string $bytes): ?EmbeddedImage {
        // The Content-Type header is not trusted; the bytes decide.
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return null;
        }

        $pixels = $info[0] * $info[1];
        if ($pixels < self::MIN_PIXELS || $pixels > self::MAX_PIXELS) {
            return null;
        }

        return match (true) {
            in_array($info['mime'], self::KEPT_AS_IS_TYPES, true) => new EmbeddedImage($bytes, $info['mime']),
            in_array($info['mime'], self::CONVERTED_TYPES, true) => $this->convertToJpeg($bytes),
            default => null,
        };
    }

    /** A GIF or animated WebP keeps only its first frame. */
    private function convertToJpeg(string $bytes): ?EmbeddedImage {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            // GD cannot decode animated WebP.
            $firstFrame = $this->firstWebpFrame($bytes);
            $image = $firstFrame === null ? false : @imagecreatefromstring($firstFrame);
        }
        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $onWhite = imagecreatetruecolor($width, $height);
        imagefill($onWhite, 0, 0, imagecolorallocate($onWhite, 255, 255, 255));
        imagecopy($onWhite, $image, 0, 0, 0, 0, $width, $height);

        ob_start();
        imagejpeg($onWhite, null, self::JPEG_QUALITY);
        $jpeg = (string)ob_get_clean();

        return $jpeg === '' ? null : new EmbeddedImage($jpeg, 'image/jpeg');
    }

    /** Re-wraps the first frame of an animated WebP as a still image, without transparency. */
    private function firstWebpFrame(string $webp): ?string {
        if (!str_starts_with($webp, 'RIFF') || substr($webp, 8, 4) !== 'WEBP') {
            return null;
        }

        foreach ($this->riffChunks($webp, 12) as [$fourCc, $chunk]) {
            if ($fourCc !== 'ANMF') {
                continue;
            }
            $frameData = substr($chunk, 8 + self::WEBP_FRAME_HEADER_BYTES);
            foreach ($this->riffChunks($frameData, 0) as [$frameFourCc, $frameChunk]) {
                if ($frameFourCc === 'VP8 ' || $frameFourCc === 'VP8L') {
                    return 'RIFF' . pack('V', 4 + strlen($frameChunk)) . 'WEBP' . $frameChunk;
                }
            }

            return null;
        }

        return null;
    }

    /** @return \Generator<int, array{string, string}> four-character code and the whole chunk, header included */
    private function riffChunks(string $data, int $offset): \Generator {
        while ($offset + 8 <= strlen($data)) {
            $payloadBytes = unpack('V', $data, $offset + 4)[1];
            $chunkBytes = 8 + $payloadBytes + ($payloadBytes & 1);
            yield [substr($data, $offset, 4), substr($data, $offset, $chunkBytes)];
            $offset += $chunkBytes;
        }
    }
}
