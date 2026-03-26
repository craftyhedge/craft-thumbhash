<?php

namespace craftyhedge\craftthumbhash\services;

use Craft;
use craft\elements\Asset;
use Thumbhash\Thumbhash;
use craftyhedge\craftthumbhash\records\ThumbhashRecord;
use yii\base\Component;

class ThumbhashService extends Component
{
    /**
     * Generate a ThumbHash string from an asset image.
     * Returns null if the asset is not a supported image.
     */
    public function generateHash(Asset $asset): ?string
    {
        if ($asset->kind !== Asset::KIND_IMAGE) {
            return null;
        }

        // Skip SVGs — can't rasterize to pixels
        $extension = strtolower($asset->getExtension());
        if ($extension === 'svg') {
            return null;
        }

        $tempPath = null;

        try {
            // Copy the asset to a temp file
            $tempPath = $asset->getCopyOfFile();

            if (!$tempPath || !file_exists($tempPath)) {
                Craft::warning("ThumbHash: Could not get copy of file for asset {$asset->id}", __METHOD__);
                return null;
            }

            // Resize and extract RGBA pixels
            if (extension_loaded('imagick')) {
                $rgba = $this->extractRgbaImagick($tempPath);
            } elseif (extension_loaded('gd')) {
                $rgba = $this->extractRgbaGd($tempPath);
            } else {
                Craft::error('ThumbHash: Neither Imagick nor GD extension is available.', __METHOD__);
                return null;
            }

            if ($rgba === null) {
                return null;
            }

            [$width, $height, $pixels] = $rgba;

            $hashArray = Thumbhash::RGBAToHash($width, $height, $pixels);
            return Thumbhash::convertHashToString($hashArray);
        } catch (\Throwable $e) {
            Craft::error("ThumbHash: Error generating hash for asset {$asset->id}: {$e->getMessage()}", __METHOD__);
            return null;
        } finally {
            if ($tempPath && file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    /**
     * Save (upsert) a thumbhash for an asset.
     */
    public function saveHash(int $assetId, string $hash): void
    {
        $record = ThumbhashRecord::findOne(['assetId' => $assetId]);

        if (!$record) {
            $record = new ThumbhashRecord();
            $record->assetId = $assetId;
        }

        $record->hash = $hash;

        if (!$record->save()) {
            Craft::error(
                'ThumbHash: Failed to save hash for asset ' . $assetId . ': ' . implode(', ', $record->getFirstErrors()),
                __METHOD__,
            );
        }
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
     * Delete the thumbhash for an asset.
     */
    public function deleteHash(int $assetId): void
    {
        ThumbhashRecord::deleteAll(['assetId' => $assetId]);
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
}
