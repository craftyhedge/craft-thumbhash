<?php

namespace craftyhedge\craftthumbhash\services;

use Craft;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\Asset;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use Psr\Http\Message\ResponseInterface;
use samdark\log\PsrMessage;
use Thumbhash\Thumbhash;
use craftyhedge\craftthumbhash\db\Table as PluginTable;
use craftyhedge\craftthumbhash\Plugin;
use craftyhedge\craftthumbhash\records\ThumbhashRecord;
use DateTimeInterface;
use yii\base\Component;
use yii\db\IntegrityException;
use yii\log\Logger;

class ThumbhashService extends Component
{
    private const LOG_CATEGORY = 'thumbhash';
    private const PAYLOAD_STATUS_READY = 'ready';
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
        );

        return $result['payload'];
    }

    /**
     * Generate hash data and return status metadata for the caller.
     *
     * @return array{status: string, reason: string|null, payload: array{hash: string, dataUrl: ?string}|null}
     */
    public function generateHashPayloadWithStatus(
        Asset $asset,
        bool $generateDataUrl = false,
    ): array {
        $assetId = (int)$asset->id;

        $this->logEvent('debug', 'thumbhash.generate.start', [
            'assetId' => $assetId,
            'generateDataUrl' => $generateDataUrl,
        ]);

        if ($asset->kind !== Asset::KIND_IMAGE) {
            $this->logEvent('debug', 'thumbhash.generate.failure', [
                'assetId' => $assetId,
                'generateDataUrl' => $generateDataUrl,
                'reason' => 'unsupported_kind',
            ]);

            return [
                'status' => self::PAYLOAD_STATUS_FAILED,
                'reason' => 'unsupported_kind',
                'payload' => null,
            ];
        }

        // Skip SVGs — can't rasterize to pixels
        $extension = strtolower($asset->getExtension());
        if ($extension === 'svg') {
            $this->logEvent('debug', 'thumbhash.generate.failure', [
                'assetId' => $assetId,
                'generateDataUrl' => $generateDataUrl,
                'reason' => 'svg_unsupported',
            ]);

            return [
                'status' => self::PAYLOAD_STATUS_FAILED,
                'reason' => 'svg_unsupported',
                'payload' => null,
            ];
        }

        return $this->generateHashPayloadFromTransform($asset, $generateDataUrl);
    }

    private function generateHashPayloadFromTransform(Asset $asset, bool $generateDataUrl): array
    {
        $assetId = (int)$asset->id;

        try {
            $transformUrl = $asset->getUrl($this->getSourceTransformDefinition(), true);

            if (!$transformUrl) {
                $this->logEvent('info', 'thumbhash.generate.failure', [
                    'assetId' => $assetId,
                    'generateDataUrl' => $generateDataUrl,
                    'reason' => 'transform_url_unavailable',
                ]);

                return [
                    'status' => self::PAYLOAD_STATUS_FAILED,
                    'reason' => 'transform_url_unavailable',
                    'payload' => null,
                ];
            }

            $tempPath = tempnam(sys_get_temp_dir(), 'thumbhash_tf_');
            if (!$tempPath) {
                $this->logEvent('error', 'thumbhash.generate.failure', [
                    'assetId' => $assetId,
                    'generateDataUrl' => $generateDataUrl,
                    'reason' => 'temp_file_unavailable',
                ]);

                return [
                    'status' => self::PAYLOAD_STATUS_FAILED,
                    'reason' => 'temp_file_unavailable',
                    'payload' => null,
                ];
            }

            try {
                $fetched = $this->fetchTransformToPath($transformUrl, $tempPath, $assetId);

                if (!$fetched) {
                    $this->logEvent('info', 'thumbhash.generate.failure', [
                        'assetId' => $assetId,
                        'generateDataUrl' => $generateDataUrl,
                        'reason' => 'bytes_unavailable',
                    ]);

                    return [
                        'status' => self::PAYLOAD_STATUS_FAILED,
                        'reason' => 'bytes_unavailable',
                        'payload' => null,
                    ];
                }

                $payload = $this->generateHashPayloadFromPath($tempPath, $generateDataUrl);

                if ($payload === null) {
                    $this->logEvent('warning', 'thumbhash.generate.failure', [
                        'assetId' => $assetId,
                        'generateDataUrl' => $generateDataUrl,
                        'reason' => 'extract_failed',
                    ]);
                } else {
                    $this->logEvent('debug', 'thumbhash.generate.success', [
                        'assetId' => $assetId,
                        'generateDataUrl' => $generateDataUrl,
                    ]);
                }

                return [
                    'status' => $payload === null ? self::PAYLOAD_STATUS_FAILED : self::PAYLOAD_STATUS_READY,
                    'reason' => $payload === null ? 'extract_failed' : null,
                    'payload' => $payload,
                ];
            } finally {
                if (file_exists($tempPath)) {
                    unlink($tempPath);
                }
            }
        } catch (\Throwable $e) {
            $this->logEvent('warning', 'thumbhash.generate.failure', [
                'assetId' => $assetId,
                'generateDataUrl' => $generateDataUrl,
                'reason' => 'fetch_exception',
                'exceptionType' => $e::class,
            ]);

            return [
                'status' => self::PAYLOAD_STATUS_FAILED,
                'reason' => 'fetch_exception',
                'payload' => null,
            ];
        }
    }

    private function generateHashPayloadFromPath(string $path, bool $generateDataUrl): ?array
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
        $dataUrl = $generateDataUrl ? $this->hashArrayToDataUrl($hashArray) : null;

        return [
            'hash' => $hash,
            'dataUrl' => $dataUrl,
        ];
    }

    private function fetchTransformToPath(string $url, string $targetPath, ?int $assetId = null): bool
    {
        $startedAt = microtime(true);
        $maxBytes = $this->transformFetchMaxBytes();
        $normalizedUrl = $this->normalizeTransformUrl($url);

        if (!$normalizedUrl) {
            $this->logEvent('info', 'thumbhash.transform.fetch.result', [
                'assetId' => $assetId,
                'reason' => 'normalize_failed',
                'durationMs' => $this->durationMs($startedAt),
            ]);

            return false;
        }

        $sanitizedUrl = $this->sanitizeUrlForLog($normalizedUrl);

        $this->logEvent('debug', 'thumbhash.transform.fetch.start', [
            'assetId' => $assetId,
            'url' => $sanitizedUrl,
        ]);

        try {
            if (str_starts_with($normalizedUrl, 'file://')) {
                $path = $this->decodeFileUrlPath($normalizedUrl);
                if (!is_file($path)) {
                    $this->logEvent('info', 'thumbhash.transform.fetch.result', [
                        'assetId' => $assetId,
                        'url' => $sanitizedUrl,
                        'reason' => 'local_file_missing',
                        'durationMs' => $this->durationMs($startedAt),
                    ]);

                    return false;
                }

                $size = @filesize($path);
                if (is_int($size) && $size > $maxBytes) {
                    $this->logEvent('info', 'thumbhash.transform.fetch.result', [
                        'assetId' => $assetId,
                        'url' => $sanitizedUrl,
                        'reason' => 'response_too_large',
                        'maxBytes' => $maxBytes,
                        'bytes' => $size,
                        'durationMs' => $this->durationMs($startedAt),
                    ]);

                    return false;
                }

                if (!@copy($path, $targetPath)) {
                    $this->logEvent('warning', 'thumbhash.transform.fetch.result', [
                        'assetId' => $assetId,
                        'url' => $sanitizedUrl,
                        'reason' => 'copy_failed',
                        'durationMs' => $this->durationMs($startedAt),
                    ]);

                    return false;
                }

                $targetSize = @filesize($targetPath);
                if (!is_int($targetSize) || $targetSize <= 0) {
                    $this->logEvent('info', 'thumbhash.transform.fetch.result', [
                        'assetId' => $assetId,
                        'url' => $sanitizedUrl,
                        'reason' => 'empty_body',
                        'durationMs' => $this->durationMs($startedAt),
                        'bytes' => 0,
                    ]);

                    return false;
                }

                if ($targetSize > $maxBytes) {
                    $this->logEvent('info', 'thumbhash.transform.fetch.result', [
                        'assetId' => $assetId,
                        'url' => $sanitizedUrl,
                        'reason' => 'response_too_large',
                        'maxBytes' => $maxBytes,
                        'bytes' => $targetSize,
                        'durationMs' => $this->durationMs($startedAt),
                    ]);

                    return false;
                }

                $this->logEvent('debug', 'thumbhash.transform.fetch.result', [
                    'assetId' => $assetId,
                    'url' => $sanitizedUrl,
                    'reason' => 'ok',
                    'durationMs' => $this->durationMs($startedAt),
                    'bytes' => $targetSize,
                ]);

                return true;
            }

            if (is_file($normalizedUrl)) {
                $size = @filesize($normalizedUrl);
                if (is_int($size) && $size > $maxBytes) {
                    $this->logEvent('info', 'thumbhash.transform.fetch.result', [
                        'assetId' => $assetId,
                        'url' => $sanitizedUrl,
                        'reason' => 'response_too_large',
                        'maxBytes' => $maxBytes,
                        'bytes' => $size,
                        'durationMs' => $this->durationMs($startedAt),
                    ]);

                    return false;
                }

                if (!@copy($normalizedUrl, $targetPath)) {
                    $this->logEvent('warning', 'thumbhash.transform.fetch.result', [
                        'assetId' => $assetId,
                        'url' => $sanitizedUrl,
                        'reason' => 'copy_failed',
                        'durationMs' => $this->durationMs($startedAt),
                    ]);

                    return false;
                }

                $targetSize = @filesize($targetPath);
                if (!is_int($targetSize) || $targetSize <= 0) {
                    $this->logEvent('info', 'thumbhash.transform.fetch.result', [
                        'assetId' => $assetId,
                        'url' => $sanitizedUrl,
                        'reason' => 'empty_body',
                        'durationMs' => $this->durationMs($startedAt),
                        'bytes' => 0,
                    ]);

                    return false;
                }

                if ($targetSize > $maxBytes) {
                    $this->logEvent('info', 'thumbhash.transform.fetch.result', [
                        'assetId' => $assetId,
                        'url' => $sanitizedUrl,
                        'reason' => 'response_too_large',
                        'maxBytes' => $maxBytes,
                        'bytes' => $targetSize,
                        'durationMs' => $this->durationMs($startedAt),
                    ]);

                    return false;
                }

                $this->logEvent('debug', 'thumbhash.transform.fetch.result', [
                    'assetId' => $assetId,
                    'url' => $sanitizedUrl,
                    'reason' => 'ok',
                    'durationMs' => $this->durationMs($startedAt),
                    'bytes' => $targetSize,
                ]);

                return true;
            }

            $client = Craft::createGuzzleClient([
                'timeout' => $this->transformFetchTimeout(),
                'connect_timeout' => $this->transformFetchConnectTimeout(),
                'read_timeout' => $this->transformFetchReadTimeout(),
                'http_errors' => false,
                'allow_redirects' => false,
            ]);

            $response = $client->get($normalizedUrl, [
                'sink' => $targetPath,
                'on_headers' => function (ResponseInterface $response) use ($maxBytes): void {
                    $contentLength = trim($response->getHeaderLine('Content-Length'));
                    if ($contentLength !== '' && is_numeric($contentLength) && (int)$contentLength > $maxBytes) {
                        throw new \RuntimeException('response_too_large');
                    }

                    $contentType = $response->getHeaderLine('Content-Type');
                    if (!$this->isSupportedTransformContentType($contentType)) {
                        throw new \RuntimeException('unsupported_content_type');
                    }
                },
                'progress' => function (
                    $downloadTotal,
                    $downloaded,
                    $uploadTotal,
                    $uploaded
                ) use ($maxBytes): void {
                    $downloaded = (int)$downloaded;

                    if ($downloaded > $maxBytes) {
                        throw new \RuntimeException('response_too_large');
                    }
                },
            ]);
            $statusCode = $response->getStatusCode();

            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logEvent('info', 'thumbhash.transform.fetch.result', [
                    'assetId' => $assetId,
                    'url' => $sanitizedUrl,
                    'reason' => 'http_non_2xx',
                    'statusCode' => $statusCode,
                    'durationMs' => $this->durationMs($startedAt),
                ]);

                return false;
            }

            $bytes = @filesize($targetPath);
            if (!is_int($bytes) || $bytes <= 0) {
                $this->logEvent('info', 'thumbhash.transform.fetch.result', [
                    'assetId' => $assetId,
                    'url' => $sanitizedUrl,
                    'reason' => 'empty_body',
                    'statusCode' => $statusCode,
                    'durationMs' => $this->durationMs($startedAt),
                    'bytes' => 0,
                    'maxBytes' => $maxBytes,
                ]);

                return false;
            }

            if ($bytes > $maxBytes) {
                $this->logEvent('info', 'thumbhash.transform.fetch.result', [
                    'assetId' => $assetId,
                    'url' => $sanitizedUrl,
                    'reason' => 'response_too_large',
                    'statusCode' => $statusCode,
                    'durationMs' => $this->durationMs($startedAt),
                    'bytes' => $bytes,
                    'maxBytes' => $maxBytes,
                ]);

                return false;
            }

            $this->logEvent('debug', 'thumbhash.transform.fetch.result', [
                'assetId' => $assetId,
                'url' => $sanitizedUrl,
                'reason' => 'ok',
                'statusCode' => $statusCode,
                'durationMs' => $this->durationMs($startedAt),
                'bytes' => $bytes,
                'maxBytes' => $maxBytes,
            ]);

            return true;
        } catch (\Throwable $e) {
            $reason = $this->transformFetchFailureReason($e);

            $this->logEvent('warning', 'thumbhash.transform.fetch.result', [
                'assetId' => $assetId,
                'url' => $sanitizedUrl,
                'reason' => $reason,
                'durationMs' => $this->durationMs($startedAt),
                'exceptionType' => $e::class,
            ]);

            if (is_file($targetPath)) {
                @unlink($targetPath);
            }

            return false;
        }
    }

    private function decodeFileUrlPath(string $fileUrl): string
    {
        $path = parse_url($fileUrl, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            return '';
        }

        return rawurldecode($path);
    }

    private function transformFetchFailureReason(\Throwable $e): string
    {
        $message = strtolower(trim($e->getMessage()));

        if ($message === 'response_too_large') {
            return 'response_too_large';
        }

        if ($message === 'unsupported_content_type') {
            return 'unsupported_content_type';
        }

        if ($message === 'stream_read_timeout') {
            return 'stream_read_timeout';
        }

        return 'fetch_exception';
    }

    private function isSupportedTransformContentType(string $contentType): bool
    {
        $normalized = strtolower(trim($contentType));
        if ($normalized === '') {
            return true;
        }

        $semiPos = strpos($normalized, ';');
        if ($semiPos !== false) {
            $normalized = trim(substr($normalized, 0, $semiPos));
        }

        if (str_starts_with($normalized, 'image/')) {
            return true;
        }

        return in_array($normalized, ['application/octet-stream', 'binary/octet-stream'], true);
    }

    private function sanitizeUrlForLog(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return 'redacted';
        }

        if (str_starts_with($trimmed, 'file://')) {
            $path = substr($trimmed, 7);
            $baseName = basename($path);

            return $baseName !== '' ? "local-file:{$baseName}" : 'local-file';
        }

        if (str_starts_with($trimmed, '/') || str_starts_with($trimmed, './') || str_starts_with($trimmed, '../')) {
            $baseName = basename($trimmed);

            return $baseName !== '' ? "local-file:{$baseName}" : 'local-file';
        }

        $parts = parse_url($trimmed);
        if (!is_array($parts)) {
            return 'redacted';
        }

        if (!isset($parts['scheme'], $parts['host'])) {
            return 'redacted';
        }

        $scheme = strtolower((string)$parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return 'redacted';
        }

        $host = (string)$parts['host'];
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $path = isset($parts['path']) && is_string($parts['path']) && $parts['path'] !== ''
            ? $parts['path']
            : '/';

        return "{$scheme}://{$host}{$port}{$path}";
    }

    private function durationMs(float $startedAt): int
    {
        return (int)round((microtime(true) - $startedAt) * 1000);
    }

    private function logEvent(string $level, string $event, array $context = []): void
    {
        $message = new PsrMessage($event, $context);

        if ($level === 'debug') {
            Craft::getLogger()->log($message, Logger::LEVEL_TRACE, self::LOG_CATEGORY);
            return;
        }

        if ($level === 'warning') {
            Craft::warning($message, self::LOG_CATEGORY);
            return;
        }

        if ($level === 'error') {
            Craft::error($message, self::LOG_CATEGORY);
            return;
        }

        Craft::info($message, self::LOG_CATEGORY);
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

        try {
            $base = UrlHelper::siteUrl('');

            if (is_string($base) && $base !== '') {
                if (str_starts_with($base, '//')) {
                    return 'https';
                }

                $parts = parse_url($base);
                if (is_array($parts) && isset($parts['scheme']) && is_string($parts['scheme'])) {
                    return strtolower($parts['scheme']);
                }
            }
        } catch (
            \Throwable
        ) {
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
     * Transform definition used for hash generation.
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
            ];
        }

        $transform = $plugin->getSettings()->sourceTransform;

        if (!is_array($transform) || empty($transform)) {
            return [
                'mode' => 'fit',
                'width' => 100,
            ];
        }

        return $transform;
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

        $this->applyHashDataToRecord(
            $record,
            $hash,
            $dataUrl,
            $sourceModifiedAt,
            $sourceSize,
            $sourceWidth,
            $sourceHeight,
        );

        try {
            if (!$this->saveHashRecord($record, $assetId)) {
                return;
            }
        } catch (IntegrityException $e) {
            if (!$this->isUniqueAssetIdViolation($e)) {
                throw $e;
            }

            $this->logEvent('info', 'thumbhash.save.collision_retry', [
                'assetId' => $assetId,
                'exceptionType' => $e::class,
            ]);

            $record = ThumbhashRecord::findOne(['assetId' => $assetId]);

            if (!$record) {
                $this->logEvent('warning', 'thumbhash.save.failure', [
                    'assetId' => $assetId,
                    'reason' => 'collision_retry_missing_record',
                ]);

                return;
            }

            $this->applyHashDataToRecord(
                $record,
                $hash,
                $dataUrl,
                $sourceModifiedAt,
                $sourceSize,
                $sourceWidth,
                $sourceHeight,
            );

            if (!$this->saveHashRecord($record, $assetId)) {
                return;
            }
        }

        unset($this->dataUrlCache[$assetId]);

        if ($dataUrl !== null) {
            $this->dataUrlCache[$assetId] = $dataUrl;
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

    private function applyHashDataToRecord(
        ThumbhashRecord $record,
        ?string $hash,
        ?string $dataUrl,
        ?int $sourceModifiedAt,
        ?int $sourceSize,
        ?int $sourceWidth,
        ?int $sourceHeight,
    ): void {
        $record->hash = $hash;
        $record->dataUrl = $dataUrl;
        $record->sourceModifiedAt = $sourceModifiedAt;
        $record->sourceSize = $sourceSize;
        $record->sourceWidth = $sourceWidth;
        $record->sourceHeight = $sourceHeight;
    }

    private function saveHashRecord(ThumbhashRecord $record, int $assetId): bool
    {
        if (!$record->save()) {
            Craft::error(
                'ThumbHash: Failed to save hash for asset ' . $assetId . ': ' . implode(', ', $record->getFirstErrors()),
                __METHOD__,
            );

            return false;
        }

        return true;
    }

    private function isUniqueAssetIdViolation(IntegrityException $e): bool
    {
        $message = strtolower(trim($e->getMessage()));

        if ($message === '') {
            return false;
        }

        $mentionsAssetId = str_contains($message, 'assetid') || str_contains($message, 'asset_id');

        if (!$mentionsAssetId) {
            return false;
        }

        return str_contains($message, 'duplicate') || str_contains($message, 'unique');
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
     * Whether PNG data URL compression should be used.
     */
    public function shouldUsePngCompression(): bool
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return true;
        }

        return (bool)$plugin->getSettings()->pngCompressionEnabled;
    }

    /**
     * PNG compression level (0-9).
     */
    public function pngCompressionLevel(): int
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return 9;
        }

        return max(0, min(9, (int)$plugin->getSettings()->pngCompressionLevel));
    }

    /**
     * Whether metadata should be stripped from Imagick PNG output.
     */
    public function shouldStripPngMetadata(): bool
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return true;
        }

        return (bool)$plugin->getSettings()->pngStripMetadata;
    }

    /**
     * Maximum transform fetch response size in bytes.
     */
    public function transformFetchMaxBytes(): int
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return 5 * 1024 * 1024;
        }

        return max(262144, (int)$plugin->getSettings()->transformFetchMaxBytes);
    }

    /**
     * Total timeout in seconds for transform fetch requests.
     */
    public function transformFetchTimeout(): float
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return 20.0;
        }

        return max(1.0, (float)$plugin->getSettings()->transformFetchTimeout);
    }

    /**
     * Connection timeout in seconds for transform fetch requests.
     */
    public function transformFetchConnectTimeout(): float
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return 5.0;
        }

        return max(0.5, (float)$plugin->getSettings()->transformFetchConnectTimeout);
    }

    /**
     * Per-read timeout in seconds for streamed transform responses.
     */
    public function transformFetchReadTimeout(): float
    {
        $plugin = Plugin::getInstance();

        if ($plugin === null) {
            return 10.0;
        }

        return max(0.5, (float)$plugin->getSettings()->transformFetchReadTimeout);
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
     * Get utility grid rows from persisted PNG placeholders.
     *
     * @return array<int, array{assetId: int, dataUrl: string}>
     */
    public function getUtilityPngRows(): array
    {
        return $this->getUtilityPngRowsSnapshot()['rows'];
    }

    /**
     * Get utility fallback rows from persisted thumbhash strings.
     *
     * @return array<int, array{assetId: int, hash: string}>
     */
    public function getUtilityHashRows(): array
    {
        return $this->getUtilityHashRowsSnapshot()['rows'];
    }

    /**
     * @return array{rows: array<int, array{assetId: int, dataUrl: string}>, cursorUpdatedAt: string|null, cursorAssetId: int, delta: bool}
     */
    public function getUtilityPngRowsSnapshot(?string $sinceUpdatedAt = null, int $sinceAssetId = 0): array
    {
        $delta = $sinceUpdatedAt !== null && $sinceUpdatedAt !== '';

        $query = $this->utilityBaseQuery()
            ->select(['thumbhashes.assetId', 'thumbhashes.dataUrl', 'thumbhashes.dateUpdated'])
            ->andWhere(['not', ['thumbhashes.dataUrl' => null]])
            ->andWhere(['!=', 'thumbhashes.dataUrl', '']);

        if ($delta) {
            $query
                ->andWhere([
                    'or',
                    ['>', 'thumbhashes.dateUpdated', $sinceUpdatedAt],
                    [
                        'and',
                        ['thumbhashes.dateUpdated' => $sinceUpdatedAt],
                        ['>', 'thumbhashes.assetId', max(0, $sinceAssetId)],
                    ],
                ])
                ->orderBy(['thumbhashes.dateUpdated' => SORT_ASC, 'thumbhashes.assetId' => SORT_ASC]);
        } else {
            $query->orderBy(['thumbhashes.assetId' => SORT_ASC]);
        }

        /** @var array<int, array{assetId: int|string, dataUrl: string|null, dateUpdated: mixed}> $records */
        $records = $query->all();

        $rows = array_map(static function(array $record): array {
            return [
                'assetId' => (int)($record['assetId'] ?? 0),
                'dataUrl' => (string)($record['dataUrl'] ?? ''),
            ];
        }, $records);

        [$cursorUpdatedAt, $cursorAssetId] = $this->utilityCursorFromRows(
            $records,
            $sinceUpdatedAt,
            $sinceAssetId,
        );

        return [
            'rows' => $rows,
            'cursorUpdatedAt' => $cursorUpdatedAt,
            'cursorAssetId' => $cursorAssetId,
            'delta' => $delta,
        ];
    }

    /**
     * @return array{rows: array<int, array{assetId: int, hash: string}>, cursorUpdatedAt: string|null, cursorAssetId: int, delta: bool}
     */
    public function getUtilityHashRowsSnapshot(?string $sinceUpdatedAt = null, int $sinceAssetId = 0): array
    {
        $delta = $sinceUpdatedAt !== null && $sinceUpdatedAt !== '';

        $query = $this->utilityBaseQuery()
            ->select(['thumbhashes.assetId', 'thumbhashes.hash', 'thumbhashes.dateUpdated'])
            ->andWhere(['not', ['thumbhashes.hash' => null]])
            ->andWhere(['!=', 'thumbhashes.hash', '']);

        if ($delta) {
            $query
                ->andWhere([
                    'or',
                    ['>', 'thumbhashes.dateUpdated', $sinceUpdatedAt],
                    [
                        'and',
                        ['thumbhashes.dateUpdated' => $sinceUpdatedAt],
                        ['>', 'thumbhashes.assetId', max(0, $sinceAssetId)],
                    ],
                ])
                ->orderBy(['thumbhashes.dateUpdated' => SORT_ASC, 'thumbhashes.assetId' => SORT_ASC]);
        } else {
            $query->orderBy(['thumbhashes.assetId' => SORT_ASC]);
        }

        /** @var array<int, array{assetId: int|string, hash: string|null, dateUpdated: mixed}> $records */
        $records = $query->all();

        $rows = array_map(static function(array $record): array {
            return [
                'assetId' => (int)($record['assetId'] ?? 0),
                'hash' => (string)($record['hash'] ?? ''),
            ];
        }, $records);

        [$cursorUpdatedAt, $cursorAssetId] = $this->utilityCursorFromRows(
            $records,
            $sinceUpdatedAt,
            $sinceAssetId,
        );

        return [
            'rows' => $rows,
            'cursorUpdatedAt' => $cursorUpdatedAt,
            'cursorAssetId' => $cursorAssetId,
            'delta' => $delta,
        ];
    }

    /**
     * @param array<int, array{assetId: int|string, dateUpdated: mixed}> $rows
     * @return array{0: string|null, 1: int}
     */
    private function utilityCursorFromRows(array $rows, ?string $sinceUpdatedAt, int $sinceAssetId): array
    {
        $cursorUpdatedAt = null;
        $cursorAssetId = 0;

        if ($sinceUpdatedAt !== null && $sinceUpdatedAt !== '') {
            $cursorUpdatedAt = $sinceUpdatedAt;
            $cursorAssetId = max(0, $sinceAssetId);
        }

        foreach ($rows as $row) {
            $updatedAt = $this->normalizeCursorDateUpdated($row['dateUpdated'] ?? null);

            if ($updatedAt === null) {
                continue;
            }

            $assetId = (int)($row['assetId'] ?? 0);

            if (
                $cursorUpdatedAt === null ||
                $updatedAt > $cursorUpdatedAt ||
                ($updatedAt === $cursorUpdatedAt && $assetId > $cursorAssetId)
            ) {
                $cursorUpdatedAt = $updatedAt;
                $cursorAssetId = $assetId;
            }
        }

        return [$cursorUpdatedAt, $cursorAssetId];
    }

    private function utilityBaseQuery(): Query
    {
        $query = (new Query())
            ->from(['thumbhashes' => PluginTable::THUMBHASHES])
            ->innerJoin(['assets' => CraftTable::ASSETS], '[[assets.id]] = [[thumbhashes.assetId]]')
            ->innerJoin(['elements' => CraftTable::ELEMENTS], '[[elements.id]] = [[assets.id]]')
            ->where([
                'elements.dateDeleted' => null,
                'elements.archived' => false,
                'assets.kind' => Asset::KIND_IMAGE,
            ])
            ->andWhere(Db::parseParam('assets.filename', ['not', '*.svg']));

        $volumeIds = $this->resolveUtilityVolumeIds();
        if ($volumeIds === []) {
            $query->andWhere('0=1');
            return $query;
        }

        if ($volumeIds !== null) {
            $query->andWhere(['assets.volumeId' => $volumeIds]);
        }

        return $query;
    }

    /**
     * @return array<int>|null
     */
    private function resolveUtilityVolumeIds(): ?array
    {
        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return null;
        }

        $volumes = $plugin->getSettings()->volumes;

        if ($volumes === null || $volumes === '*') {
            return null;
        }

        $ids = [];
        $handles = [];

        foreach ((array)$volumes as $volume) {
            if (is_int($volume) || (is_string($volume) && ctype_digit($volume))) {
                $ids[] = (int)$volume;
                continue;
            }

            $handle = is_string($volume) ? trim($volume) : '';
            if ($handle !== '') {
                $handles[] = $handle;
            }
        }

        if (!empty($handles)) {
            $handleIds = (new Query())
                ->select(['id'])
                ->from([CraftTable::VOLUMES])
                ->where(Db::parseParam('handle', $handles))
                ->andWhere(['dateDeleted' => null])
                ->column();

            foreach ($handleIds as $id) {
                if (is_numeric($id)) {
                    $ids[] = (int)$id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function normalizeCursorDateUpdated(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return Db::prepareDateForDb($value);
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed !== '' ? $trimmed : null;
        }

        return null;
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

        return $this->hashArrayToDataUrl($hashArray);
    }

    /**
     * Convert a ThumbHash byte array into a PNG data URL.
     *
     * Tries compressed PNG encoding first, then falls back to the library's
     * built-in uncompressed encoder if compression fails.
     *
     * @param array<int> $hashArray
     */
    private function hashArrayToDataUrl(array $hashArray): string
    {
        if (!$this->shouldUsePngCompression()) {
            return Thumbhash::toDataURL($hashArray);
        }

        $image = Thumbhash::hashToRGBA($hashArray);

        $w = (int)($image['w'] ?? 0);
        $h = (int)($image['h'] ?? 0);
        $rgba = $image['rgba'] ?? null;

        if (
            $w > 0 &&
            $h > 0 &&
            is_array($rgba) &&
            count($rgba) === $w * $h * 4
        ) {
            $pngBytes = $this->encodeCompressedPngBytes($w, $h, $rgba);
            if ($pngBytes !== null) {
                return 'data:image/png;base64,' . base64_encode($pngBytes);
            }
        }

        return Thumbhash::toDataURL($hashArray);
    }

    /**
     * Encode RGBA pixel data into compressed PNG bytes.
     *
     * @param array<int> $rgba
     */
    private function encodeCompressedPngBytes(int $w, int $h, array $rgba): ?string
    {
        if (extension_loaded('imagick')) {
            $bytes = $this->encodeCompressedPngBytesImagick($w, $h, $rgba);
            if ($bytes !== null) {
                return $bytes;
            }
        }

        if (extension_loaded('gd')) {
            return $this->encodeCompressedPngBytesGd($w, $h, $rgba);
        }

        return null;
    }

    /**
     * Encode RGBA data with Imagick PNG ZIP compression.
     *
     * @param array<int> $rgba
     */
    private function encodeCompressedPngBytesImagick(int $w, int $h, array $rgba): ?string
    {
        try {
            $compressionLevel = $this->pngCompressionLevel();
            $image = new \Imagick();
            $image->newImage($w, $h, 'transparent', 'png');
            $image->importImagePixels(0, 0, $w, $h, 'RGBA', \Imagick::PIXEL_CHAR, $rgba);
            $image->setImageFormat('png');
            $image->setImageDepth(8);
            $image->setImageCompression(\Imagick::COMPRESSION_ZIP);
            $image->setOption('png:compression-level', (string)$compressionLevel);
            if ($this->shouldStripPngMetadata()) {
                $image->stripImage();
            }

            $bytes = $image->getImageBlob();
            $image->clear();
            $image->destroy();

            return $bytes !== '' ? $bytes : null;
        } catch (\Throwable $e) {
            Craft::warning("ThumbHash: Imagick PNG compression failed: {$e->getMessage()}", __METHOD__);
            return null;
        }
    }

    /**
     * Encode RGBA data with GD PNG compression (level 9).
     *
     * @param array<int> $rgba
     */
    private function encodeCompressedPngBytesGd(int $w, int $h, array $rgba): ?string
    {
        try {
            $compressionLevel = $this->pngCompressionLevel();
            $image = imagecreatetruecolor($w, $h);
            if ($image === false) {
                return null;
            }

            imagealphablending($image, false);
            imagesavealpha($image, true);

            $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
            if ($transparent !== false) {
                imagefill($image, 0, 0, $transparent);
            }

            $i = 0;
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $r = (int)$rgba[$i++];
                    $g = (int)$rgba[$i++];
                    $b = (int)$rgba[$i++];
                    $a = (int)$rgba[$i++];

                    // Convert 8-bit alpha (0 transparent..255 opaque) to GD alpha (127 transparent..0 opaque).
                    $gdAlpha = 127 - (int)round(($a / 255) * 127);
                    $color = imagecolorallocatealpha($image, $r, $g, $b, max(0, min(127, $gdAlpha)));
                    if ($color !== false) {
                        imagesetpixel($image, $x, $y, $color);
                    }
                }
            }

            ob_start();
            $ok = imagepng($image, null, $compressionLevel);
            $bytes = ob_get_clean();
            imagedestroy($image);

            if (!$ok || !is_string($bytes) || $bytes === '') {
                return null;
            }

            return $bytes;
        } catch (\Throwable $e) {
            Craft::warning("ThumbHash: GD PNG compression failed: {$e->getMessage()}", __METHOD__);
            return null;
        }
    }

    /**
     * Delete the thumbhash for an asset.
     */
    public function deleteHash(int $assetId): void
    {
        ThumbhashRecord::deleteAll(['assetId' => $assetId]);
        unset($this->dataUrlCache[$assetId]);
    }

    /**
     * Delete all stored thumbhash records.
     */
    public function clearAllHashes(): int
    {
        $deleted = ThumbhashRecord::deleteAll();
        $this->dataUrlCache = [];

        return $deleted;
    }

    /**
     * Clear only stored PNG data URLs while keeping hash records.
     */
    public function clearAllDataUrls(): int
    {
        $updated = ThumbhashRecord::updateAll(
            ['dataUrl' => null],
            ['not', ['dataUrl' => null]],
        );

        $this->dataUrlCache = [];

        return $updated;
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
