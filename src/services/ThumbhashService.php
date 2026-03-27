<?php

namespace craftyhedge\craftthumbhash\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\UrlHelper;
use Thumbhash\Thumbhash;
use craftyhedge\craftthumbhash\Plugin;
use craftyhedge\craftthumbhash\records\ThumbhashRecord;
use DateTimeInterface;
use yii\base\Component;

class ThumbhashService extends Component
{
    private const PAYLOAD_STATUS_READY = 'ready';
    private const PAYLOAD_STATUS_PENDING = 'pending';
    private const PAYLOAD_STATUS_FAILED = 'failed';

    /**
     * In-memory cache for data URLs within the same request.
     */
    private array $dataUrlCache = [];
    /**
     * Generate a ThumbHash string from an asset image.
     * Returns null if the asset is not a supported image.
     */
    public function generateHash(Asset $asset): ?string
    {
        $generated = $this->generateHashPayload($asset, false);

        return $generated['hash'] ?? null;
    }

    /**
     * Generate hash data from an asset image in a single pass.
     *
     * @return array{hash: string, dataUrl: ?string}|null
     */
    public function generateHashPayload(Asset $asset, bool $generateDataUrl = false): ?array
    {
        $result = $this->generateHashPayloadWithStatus(
            $asset,
            $generateDataUrl,
            $this->shouldUseTransformSource(),
        );

        return $result['payload'];
    }

    /**
     * Generate hash data and return status metadata for queue retry behavior.
     *
     * @return array{status: string, payload: array{hash: string, dataUrl: ?string}|null}
     */
    public function generateHashPayloadWithStatus(
        Asset $asset,
        bool $generateDataUrl = false,
        bool $useTransformSource = false,
    ): array {
        if ($asset->kind !== Asset::KIND_IMAGE) {
            return [
                'status' => self::PAYLOAD_STATUS_FAILED,
                'payload' => null,
            ];
        }

        // Skip SVGs — can't rasterize to pixels
        $extension = strtolower($asset->getExtension());
        if ($extension === 'svg') {
            return [
                'status' => self::PAYLOAD_STATUS_FAILED,
                'payload' => null,
            ];
        }

        if ($useTransformSource) {
            $result = $this->generateHashPayloadFromTransform($asset, $generateDataUrl);

            if ($result['status'] !== self::PAYLOAD_STATUS_FAILED) {
                return $result;
            }
        }

        return $this->generateHashPayloadFromOriginal($asset, $generateDataUrl);
    }

    private function generateHashPayloadFromOriginal(Asset $asset, bool $generateDataUrl): array
    {
        $tempPath = null;

        try {
            // Copy the asset to a temp file
            $tempPath = $asset->getCopyOfFile();

            if (!$tempPath || !file_exists($tempPath)) {
                Craft::warning("ThumbHash: Could not get copy of file for asset {$asset->id}", __METHOD__);
                return [
                    'status' => self::PAYLOAD_STATUS_FAILED,
                    'payload' => null,
                ];
            }

            $payload = $this->generateHashPayloadFromPath($asset, $tempPath, $generateDataUrl);

            return [
                'status' => $payload === null ? self::PAYLOAD_STATUS_FAILED : self::PAYLOAD_STATUS_READY,
                'payload' => $payload,
            ];
        } catch (\Throwable $e) {
            Craft::error("ThumbHash: Error generating hash for asset {$asset->id}: {$e->getMessage()}", __METHOD__);
            return [
                'status' => self::PAYLOAD_STATUS_FAILED,
                'payload' => null,
            ];
        } finally {
            if ($tempPath && file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    private function generateHashPayloadFromTransform(Asset $asset, bool $generateDataUrl): array
    {
        try {
            $transformUrl = $asset->getUrl($this->getSourceTransformDefinition(), true);

            if (!$transformUrl) {
                Craft::info("ThumbHash: Transform URL not ready for asset {$asset->id}.", 'thumbhash');
                return [
                    'status' => self::PAYLOAD_STATUS_PENDING,
                    'payload' => null,
                ];
            }

            $bytes = $this->fetchTransformBytes($transformUrl, (int)$asset->id);

            if ($bytes === null) {
                Craft::info("ThumbHash: Transform bytes unavailable for asset {$asset->id} (will retry/fallback).", 'thumbhash');
                return [
                    'status' => self::PAYLOAD_STATUS_PENDING,
                    'payload' => null,
                ];
            }

            $tempPath = tempnam(sys_get_temp_dir(), 'thumbhash_tf_');
            if (!$tempPath) {
                return [
                    'status' => self::PAYLOAD_STATUS_FAILED,
                    'payload' => null,
                ];
            }

            try {
                if (file_put_contents($tempPath, $bytes) === false) {
                    return [
                        'status' => self::PAYLOAD_STATUS_FAILED,
                        'payload' => null,
                    ];
                }

                $payload = $this->generateHashPayloadFromPath($asset, $tempPath, $generateDataUrl);

                return [
                    'status' => $payload === null ? self::PAYLOAD_STATUS_FAILED : self::PAYLOAD_STATUS_READY,
                    'payload' => $payload,
                ];
            } finally {
                if (file_exists($tempPath)) {
                    unlink($tempPath);
                }
            }
        } catch (\Throwable $e) {
            Craft::warning("ThumbHash: Transform source failed for asset {$asset->id}: {$e->getMessage()}", __METHOD__);

            return [
                'status' => self::PAYLOAD_STATUS_FAILED,
                'payload' => null,
            ];
        }
    }

    private function generateHashPayloadFromPath(Asset $asset, string $path, bool $generateDataUrl): ?array
    {
        // Resize and extract RGBA pixels
        if (extension_loaded('imagick')) {
            $rgba = $this->extractRgbaImagick($path);
        } elseif (extension_loaded('gd')) {
            $rgba = $this->extractRgbaGd($path);
        } else {
            Craft::error('ThumbHash: Neither Imagick nor GD extension is available.', __METHOD__);
            return null;
        }

        if ($rgba === null) {
            return null;
        }

        [$width, $height, $pixels] = $rgba;

        $hashArray = Thumbhash::RGBAToHash($width, $height, $pixels);
        $hash = Thumbhash::convertHashToString($hashArray);
        $dataUrl = $generateDataUrl ? Thumbhash::toDataURL($hashArray) : null;

        return [
            'hash' => $hash,
            'dataUrl' => $dataUrl,
        ];
    }

    private function fetchTransformBytes(string $url, ?int $assetId = null): ?string
    {
        $normalizedUrl = $this->normalizeTransformUrl($url);

        if (!$normalizedUrl) {
            $assetLabel = $assetId !== null ? " for asset {$assetId}" : '';
            Craft::info("ThumbHash: Transform URL could not be normalized{$assetLabel}.", 'thumbhash');
            return null;
        }

        $assetLabel = $assetId !== null ? " for asset {$assetId}" : '';
        Craft::info("ThumbHash: Fetching transform URL{$assetLabel}: {$normalizedUrl}", 'thumbhash');

        try {
            if (str_starts_with($normalizedUrl, 'file://')) {
                $path = substr($normalizedUrl, 7);
                if (!is_file($path)) {
                    Craft::info("ThumbHash: Transform file missing{$assetLabel}: {$path}", 'thumbhash');
                    return null;
                }

                $bytes = file_get_contents($path);
                if ($bytes === false) {
                    Craft::warning("ThumbHash: Failed reading transform file{$assetLabel}: {$path}", 'thumbhash');
                }
                return $bytes === false ? null : $bytes;
            }

            if (is_file($normalizedUrl)) {
                $bytes = file_get_contents($normalizedUrl);
                if ($bytes === false) {
                    Craft::warning("ThumbHash: Failed reading local transform path{$assetLabel}: {$normalizedUrl}", 'thumbhash');
                }
                return $bytes === false ? null : $bytes;
            }

            $client = Craft::createGuzzleClient([
                'timeout' => 20,
                'connect_timeout' => 5,
                'http_errors' => false,
            ]);

            $response = $client->get($normalizedUrl);
            $statusCode = $response->getStatusCode();

            if ($statusCode < 200 || $statusCode >= 300) {
                Craft::info("ThumbHash: Transform fetch returned HTTP {$statusCode}{$assetLabel}: {$normalizedUrl}", 'thumbhash');
                return null;
            }

            $bytes = (string)$response->getBody();

            if ($bytes === '') {
                Craft::info("ThumbHash: Transform fetch returned empty body{$assetLabel}: {$normalizedUrl}", 'thumbhash');
            }

            return $bytes !== '' ? $bytes : null;
        } catch (\Throwable $e) {
            Craft::warning("ThumbHash: Transform fetch error{$assetLabel}: {$e->getMessage()}", 'thumbhash');
            return null;
        }
    }

    private function normalizeTransformUrl(string $url): ?string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $trimmed)) {
            return $trimmed;
        }

        if (str_starts_with($trimmed, '//')) {
            return $this->siteScheme() . ':' . $trimmed;
        }

        if (str_starts_with($trimmed, 'file://')) {
            return $trimmed;
        }

        if (str_starts_with($trimmed, '/')) {
            $base = $this->siteBaseUrl();
            if ($base !== null) {
                return rtrim($base, '/') . $trimmed;
            }

            return UrlHelper::siteUrl(ltrim($trimmed, '/'));
        }

        return UrlHelper::siteUrl($trimmed);
    }

    private function siteScheme(): string
    {
        $request = Craft::$app->getRequest();
        if ($request !== null && method_exists($request, 'getIsSecureConnection') && $request->getIsSecureConnection()) {
            return 'https';
        }

        $base = $this->siteBaseUrl();
        if ($base !== null) {
            $parts = parse_url($base);
            if (is_array($parts) && isset($parts['scheme']) && is_string($parts['scheme'])) {
                return strtolower($parts['scheme']);
            }
        }

        return 'https';
    }

    private function siteBaseUrl(): ?string
    {
        try {
            $base = UrlHelper::siteUrl('');

            if (!is_string($base) || $base === '') {
                return null;
            }

            $parts = parse_url($base);
            if (!is_array($parts) || !isset($parts['host'])) {
                return null;
            }

            $scheme = isset($parts['scheme']) && is_string($parts['scheme'])
                ? strtolower($parts['scheme'])
                : $this->siteScheme();
            $host = $parts['host'];
            $port = isset($parts['port']) ? ':' . $parts['port'] : '';

            return $scheme . '://' . $host . $port;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether generation should use a Craft transform as source.
     */
    public function shouldUseTransformSource(): bool
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return false;
        }

        return (bool)$plugin->getSettings()->useTransformSource;
    }

    /**
     * Transform definition used for transform-source mode.
     *
     * @return array<string, mixed>
     */
    public function getSourceTransformDefinition(): array
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return [
                'mode' => 'fit',
                'width' => 100,
                'height' => 100,
            ];
        }

        $transform = $plugin->getSettings()->sourceTransform;

        if (!is_array($transform) || empty($transform)) {
            return [
                'mode' => 'fit',
                'width' => 100,
                'height' => 100,
            ];
        }

        return $transform;
    }

    public function transformSourceMaxAttempts(): int
    {
        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return 4;
        }

        return max(1, (int)$plugin->getSettings()->transformSourceMaxAttempts);
    }

    public function transformSourceRetryDelaySeconds(): int
    {
        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return 15;
        }

        return max(1, (int)$plugin->getSettings()->transformSourceRetryDelay);
    }

    /**
     * Save (upsert) a thumbhash and optional data URL for an asset.
     */
    public function saveHash(
        int $assetId,
        ?string $hash,
        ?string $dataUrl = null,
        ?int $sourceModifiedAt = null,
        ?int $sourceSize = null,
        ?int $sourceWidth = null,
        ?int $sourceHeight = null,
    ): void
    {
        $record = ThumbhashRecord::findOne(['assetId' => $assetId]);

        if (!$record) {
            $record = new ThumbhashRecord();
            $record->assetId = $assetId;
        }

        $record->hash = $hash;
        $record->dataUrl = $dataUrl;
        $record->sourceModifiedAt = $sourceModifiedAt;
        $record->sourceSize = $sourceSize;
        $record->sourceWidth = $sourceWidth;
        $record->sourceHeight = $sourceHeight;

        if (!$record->save()) {
            Craft::error(
                'ThumbHash: Failed to save hash for asset ' . $assetId . ': ' . implode(', ', $record->getFirstErrors()),
                __METHOD__,
            );
        }
    }

    /**
     * Save hash fields using source metadata derived from an asset.
     */
    public function saveHashForAsset(Asset $asset, ?string $hash, ?string $dataUrl = null): void
    {
        [$sourceModifiedAt, $sourceSize, $sourceWidth, $sourceHeight] = $this->getSourceMetadata($asset);

        $this->saveHash(
            (int)$asset->id,
            $hash,
            $dataUrl,
            $sourceModifiedAt,
            $sourceSize,
            $sourceWidth,
            $sourceHeight,
        );
    }

    /**
     * Returns true when an asset already has a current hash record.
     */
    public function isAssetCurrent(Asset $asset, ?bool $requireDataUrl = null): bool
    {
        $requireDataUrl ??= $this->shouldGenerateDataUrl();

        $record = ThumbhashRecord::findOne(['assetId' => $asset->id]);

        if (!$record || !$record->hash) {
            return false;
        }

        if ($requireDataUrl && !$record->dataUrl) {
            return false;
        }

        [$sourceModifiedAt, $sourceSize, $sourceWidth, $sourceHeight] = $this->getSourceMetadata($asset);

        if ($sourceModifiedAt === null || $sourceSize === null || $sourceWidth === null || $sourceHeight === null) {
            return false;
        }

        $recordModifiedAt = $this->normalizeNullableInt($record->sourceModifiedAt ?? null);
        $recordSize = $this->normalizeNullableInt($record->sourceSize ?? null);
        $recordWidth = $this->normalizeNullableInt($record->sourceWidth ?? null);
        $recordHeight = $this->normalizeNullableInt($record->sourceHeight ?? null);

        return $recordModifiedAt === $sourceModifiedAt
            && $recordSize === $sourceSize
            && $recordWidth === $sourceWidth
            && $recordHeight === $sourceHeight;
    }

    /**
     * Whether the plugin should pre-generate and store PNG data URLs.
     */
    public function shouldGenerateDataUrl(): bool
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return true;
        }

        return (bool)$plugin->getSettings()->generateDataUrl;
    }

    /**
     * Get the stored thumbhash string for an asset.
     */
    public function getHash(int $assetId): ?string
    {
        $record = ThumbhashRecord::findOne(['assetId' => $assetId]);

        return $record?->hash;
    }

    /**
     * Get the stored or decoded PNG data URL for an asset.
     * Returns the pre-computed value from DB if available, otherwise decodes on the fly.
     */
    public function getDataUrl(int $assetId): ?string
    {
        if (isset($this->dataUrlCache[$assetId])) {
            return $this->dataUrlCache[$assetId];
        }

        $record = ThumbhashRecord::findOne(['assetId' => $assetId]);

        if (!$record) {
            return null;
        }

        // Prefer pre-computed data URL from DB
        if ($record->dataUrl) {
            $this->dataUrlCache[$assetId] = $record->dataUrl;
            return $record->dataUrl;
        }

        // Fall back to runtime decode from hash
        if (!$record->hash) {
            return null;
        }

        try {
            $dataUrl = $this->hashToDataUrl($record->hash);
            $this->dataUrlCache[$assetId] = $dataUrl;

            return $dataUrl;
        } catch (\Throwable $e) {
            Craft::error("ThumbHash: Error decoding hash for asset {$assetId}: {$e->getMessage()}", __METHOD__);
            return null;
        }
    }

    /**
     * Decode a base64 thumbhash string to a PNG data URL.
     */
    public function hashToDataUrl(string $hash): string
    {
        $hashArray = Thumbhash::convertStringToHash($hash);

        return Thumbhash::toDataURL($hashArray);
    }

    /**
     * Delete the thumbhash for an asset.
     */
    public function deleteHash(int $assetId): void
    {
        ThumbhashRecord::deleteAll(['assetId' => $assetId]);
    }

    /**
     * Delete all stored thumbhash records.
     */
    public function clearAllHashes(): int
    {
        return ThumbhashRecord::deleteAll();
    }

    /**
     * Clear only stored PNG data URLs while keeping hash records.
     */
    public function clearAllDataUrls(): int
    {
        return ThumbhashRecord::updateAll(
            ['dataUrl' => null],
            ['not', ['dataUrl' => null]],
        );
    }

    /**
     * Extract RGBA pixels from an image using Imagick, resized to ≤100x100.
     *
     * @return array{int, int, array<int>}|null [width, height, pixels]
     */
    private function extractRgbaImagick(string $path): ?array
    {
        try {
            $image = new \Imagick($path);

            // Use first frame for animated images
            if ($image->getNumberImages() > 1) {
                $image->setIteratorIndex(0);
            }

            // Handle alpha channel: for images without native alpha (e.g. JPEG),
            // ALPHACHANNEL_ACTIVATE may init alpha to 0 (transparent) on some systems.
            // Set to opaque in that case so pixels aren't all-transparent.
            if (!$image->getImageAlphaChannel()) {
                $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_OPAQUE);
            }

            // Resize to fit within 100x100 while maintaining aspect ratio
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();

            if ($width > 100 || $height > 100) {
                $image->thumbnailImage(100, 100, true);
            }

            $width = $image->getImageWidth();
            $height = $image->getImageHeight();

            // Export raw RGBA pixel data
            $pixelData = $image->exportImagePixels(0, 0, $width, $height, 'RGBA', \Imagick::PIXEL_CHAR);

            $image->destroy();

            return [$width, $height, $pixelData];
        } catch (\Throwable $e) {
            Craft::error("ThumbHash: Imagick extraction failed: {$e->getMessage()}", __METHOD__);
            return null;
        }
    }

    /**
     * Extract RGBA pixels from an image using GD, resized to ≤100x100.
     *
     * @return array{int, int, array<int>}|null [width, height, pixels]
     */
    private function extractRgbaGd(string $path): ?array
    {
        try {
            $source = @imagecreatefromstring(file_get_contents($path));

            if (!$source) {
                Craft::error('ThumbHash: GD could not load image.', __METHOD__);
                return null;
            }

            $origWidth = imagesx($source);
            $origHeight = imagesy($source);

            // Calculate new dimensions (max 100x100)
            $scale = min(1, 100 / max($origWidth, $origHeight));
            $width = (int) max(1, round($origWidth * $scale));
            $height = (int) max(1, round($origHeight * $scale));

            // Resize
            $resized = imagecreatetruecolor($width, $height);
            imagesavealpha($resized, true);
            imagealphablending($resized, false);
            imagecopyresampled($resized, $source, 0, 0, 0, 0, $width, $height, $origWidth, $origHeight);
            imagedestroy($source);

            // Extract RGBA pixels
            $pixels = [];
            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    $color = imagecolorat($resized, $x, $y);
                    $r = ($color >> 16) & 0xFF;
                    $g = ($color >> 8) & 0xFF;
                    $b = $color & 0xFF;
                    // GD alpha is 0 (opaque) to 127 (transparent), 7-bit
                    $a = ($color >> 24) & 0x7F;
                    $alpha = (int) round((127 - $a) / 127 * 255);
                    $pixels[] = $r;
                    $pixels[] = $g;
                    $pixels[] = $b;
                    $pixels[] = $alpha;
                }
            }

            imagedestroy($resized);

            return [$width, $height, $pixels];
        } catch (\Throwable $e) {
            Craft::error("ThumbHash: GD extraction failed: {$e->getMessage()}", __METHOD__);
            return null;
        }
    }

    /**
     * @return array{?int, ?int, ?int, ?int} [modifiedAt, size, width, height]
     */
    private function getSourceMetadata(Asset $asset): array
    {
        $modifiedAt = null;
        $dateModified = $asset->dateModified ?? null;
        if ($dateModified instanceof DateTimeInterface) {
            $modifiedAt = $dateModified->getTimestamp();
        }

        $size = $this->normalizeNullableInt($asset->size ?? null);
        $width = $this->normalizeNullableInt($asset->width ?? null);
        $height = $this->normalizeNullableInt($asset->height ?? null);

        return [$modifiedAt, $size, $width, $height];
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int)$value;
        }

        return null;
    }
}
