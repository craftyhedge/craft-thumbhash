<?php

namespace craftyhedge\craftthumbhash\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Asset;
use craft\helpers\StringHelper;
use craft\models\Volume;
use craft\models\VolumeFolder;
use craftyhedge\craftthumbhash\models\Settings;
use craftyhedge\craftthumbhash\Plugin;
use craftyhedge\craftthumbhash\records\ThumbhashRecord;
use craftyhedge\craftthumbhash\services\ThumbhashService;

class ThumbhashServiceTest extends Unit
{
    private Plugin $plugin;
    private ThumbhashService $service;

    private Volume $volume;
    private VolumeFolder $rootFolder;
    private Asset $imageAsset;
    private Asset $secondImageAsset;
    private Asset $svgAsset;
    private Asset $pdfAsset;

    /** @var string Temp dir for filesystem and test images */
    private string $tempDir;

    protected function _before(): void
    {
        parent::_before();

        $this->plugin = Plugin::getInstance();
        $this->service = $this->plugin->thumbhash;

        $this->setSettings([
            'volumes' => '*',
            'includeRules' => [],
            'ignoreRules' => [],
            'autoGenerate' => false,
            'generateDataUrl' => true,
        ]);

        $this->createFixtures();
    }

    // ── generateHashPayloadWithStatus ───────────────────────────────

    public function testGenerateRejectsNonImageAsset(): void
    {
        $result = $this->service->generateHashPayloadWithStatus($this->pdfAsset);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('unsupported_kind', $result['reason']);
        $this->assertNull($result['payload']);
    }

    public function testGenerateRejectsSvgAsset(): void
    {
        $result = $this->service->generateHashPayloadWithStatus($this->svgAsset);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('svg_unsupported', $result['reason']);
        $this->assertNull($result['payload']);
    }

    public function testGenerateHashReturnsNullForNonImage(): void
    {
        $this->assertNull($this->service->generateHash($this->pdfAsset));
    }

    public function testGenerateHashPayloadReturnsNullForSvg(): void
    {
        $this->assertNull($this->service->generateHashPayload($this->svgAsset));
    }

    public function testGenerateHashFromFetchedFile(): void
    {
        $asset = $this->createImageAssetWithFile('test-photo.jpg');
        $imagePath = $this->createTestJpeg('gen-test.jpg');

        $result = $this->service->generateHashPayloadFromFetchedFile($asset, $imagePath, false);

        $this->assertSame('ready', $result['status']);
        $this->assertNull($result['reason']);
        $this->assertNotNull($result['payload']);
        $this->assertNotEmpty($result['payload']['hash']);
        $this->assertNull($result['payload']['dataUrl']);
        @unlink($imagePath);
    }

    public function testGenerateHashPayloadWithDataUrlFromFile(): void
    {
        $asset = $this->createImageAssetWithFile('test-photo.jpg');
        $imagePath = $this->createTestJpeg('gen-test-url.jpg');

        $result = $this->service->generateHashPayloadFromFetchedFile($asset, $imagePath, true);

        $this->assertSame('ready', $result['status']);
        $this->assertNotEmpty($result['payload']['hash']);
        $this->assertStringStartsWith('data:image/png;base64,', $result['payload']['dataUrl']);
        @unlink($imagePath);
    }

    public function testGenerateHashPayloadWithoutDataUrlFromFile(): void
    {
        $asset = $this->createImageAssetWithFile('test-photo.jpg');
        $imagePath = $this->createTestJpeg('gen-test-nurl.jpg');

        $result = $this->service->generateHashPayloadFromFetchedFile($asset, $imagePath, false);

        $this->assertSame('ready', $result['status']);
        $this->assertNotEmpty($result['payload']['hash']);
        $this->assertNull($result['payload']['dataUrl']);
        @unlink($imagePath);
    }

    // ── saveHash / getHash / getDataUrl ─────────────────────────────

    public function testSaveAndGetHash(): void
    {
        $assetId = $this->imageAsset->id;
        $hash = '3wcKNxqAh4eAiIiIiHiIiIiHCAiIgI8I';

        $this->service->saveHash($assetId, $hash);

        $this->assertSame($hash, $this->service->getHash($assetId));
    }

    public function testGetHashReturnsNullWhenNotStored(): void
    {
        $this->assertNull($this->service->getHash($this->imageAsset->id));
    }

    public function testSaveHashWithDataUrl(): void
    {
        $assetId = $this->imageAsset->id;
        $hash = '3wcKNxqAh4eAiIiIiHiIiIiHCAiIgI8I';
        $dataUrl = 'data:image/png;base64,iVBOR...';

        $this->service->saveHash($assetId, $hash, $dataUrl);

        $this->assertSame($hash, $this->service->getHash($assetId));
        $this->assertSame($dataUrl, $this->service->getDataUrl($assetId));
    }

    public function testSaveHashUpdatesExistingRecord(): void
    {
        $assetId = $this->imageAsset->id;

        $this->service->saveHash($assetId, 'oldHash', 'oldDataUrl');
        $this->service->saveHash($assetId, 'newHash', 'newDataUrl');

        $this->assertSame('newHash', $this->service->getHash($assetId));
        $this->assertSame('newDataUrl', $this->service->getDataUrl($assetId));

        // Should still be a single record
        $count = ThumbhashRecord::find()->where(['assetId' => $assetId])->count();
        $this->assertEquals(1, $count);
    }

    public function testSaveHashWithSourceMetadata(): void
    {
        $assetId = $this->imageAsset->id;

        $this->service->saveHash(
            $assetId,
            'someHash',
            null,
            1700000000,
            123456,
            1920,
            1080,
        );

        $record = ThumbhashRecord::findOne(['assetId' => $assetId]);

        $this->assertNotNull($record);
        $this->assertSame('someHash', $record->hash);
        $this->assertEquals(1700000000, $record->sourceModifiedAt);
        $this->assertEquals(123456, $record->sourceSize);
        $this->assertEquals(1920, $record->sourceWidth);
        $this->assertEquals(1080, $record->sourceHeight);
    }

    // ── getDataUrl with caching and fallback ────────────────────────

    public function testGetDataUrlReturnsNullWhenNoRecord(): void
    {
        $this->assertNull($this->service->getDataUrl($this->imageAsset->id));
    }

    public function testGetDataUrlReturnsStoredDataUrl(): void
    {
        $assetId = $this->imageAsset->id;
        $dataUrl = 'data:image/png;base64,AAAA';

        $this->service->saveHash($assetId, 'hash', $dataUrl);

        $this->assertSame($dataUrl, $this->service->getDataUrl($assetId));
    }

    public function testGetDataUrlFallsBackToRuntimeDecode(): void
    {
        $assetId = $this->imageAsset->id;

        // Save hash only, no data URL — should decode at runtime
        $this->service->saveHash($assetId, '3wcKNxqAh4eAiIiIiHiIiIiHCAiIgI8I');

        $dataUrl = $this->service->getDataUrl($assetId);

        $this->assertNotNull($dataUrl);
        $this->assertStringStartsWith('data:image/png;base64,', $dataUrl);
    }

    // ── deleteHash ──────────────────────────────────────────────────

    public function testDeleteHash(): void
    {
        $assetId = $this->imageAsset->id;

        $this->service->saveHash($assetId, 'hash', 'dataUrl');
        $this->service->deleteHash($assetId);

        $this->assertNull($this->service->getHash($assetId));
        $this->assertNull($this->service->getDataUrl($assetId));
    }

    public function testDeleteHashNoOpWhenNotStored(): void
    {
        // Should not throw
        $this->service->deleteHash($this->imageAsset->id);
        $this->assertNull($this->service->getHash($this->imageAsset->id));
    }

    // ── clearAllHashes / clearAllDataUrls ───────────────────────────

    public function testClearAllHashes(): void
    {
        $this->service->saveHash($this->imageAsset->id, 'hash1', 'url1');
        $this->service->saveHash($this->secondImageAsset->id, 'hash2', 'url2');

        $deleted = $this->service->clearAllHashes();

        $this->assertEquals(2, $deleted);
        $this->assertNull($this->service->getHash($this->imageAsset->id));
        $this->assertNull($this->service->getHash($this->secondImageAsset->id));
    }

    public function testClearAllDataUrls(): void
    {
        $this->service->saveHash($this->imageAsset->id, 'hash1', 'url1');
        $this->service->saveHash($this->secondImageAsset->id, 'hash2', 'url2');

        $updated = $this->service->clearAllDataUrls();

        $this->assertEquals(2, $updated);
        // Hashes remain
        $this->assertSame('hash1', $this->service->getHash($this->imageAsset->id));
        $this->assertSame('hash2', $this->service->getHash($this->secondImageAsset->id));
        // Data URLs cleared — falls back to runtime decode
        $record1 = ThumbhashRecord::findOne(['assetId' => $this->imageAsset->id]);
        $this->assertNull($record1->dataUrl);
    }

    // ── isAssetCurrent / isAssetCurrentWithRecord ───────────────────

    public function testIsAssetCurrentReturnsFalseWithNoRecord(): void
    {
        $this->assertFalse($this->service->isAssetCurrent($this->imageAsset));
    }

    public function testIsAssetCurrentReturnsFalseWhenNullMetadata(): void
    {
        // imageAsset has no file metadata (size/width/height/dateModified are null).
        // Saving a non-null hash with null metadata must NOT make the asset current —
        // the null-metadata guard must fire before the field comparisons reach null===null.
        $this->service->saveHash($this->imageAsset->id, 'someHash');

        $this->assertFalse($this->service->isAssetCurrent($this->imageAsset, false));
    }

    public function testIsAssetCurrentReturnsFalseWithNoHash(): void
    {
        $this->service->saveHash($this->imageAsset->id, null);

        $this->assertFalse($this->service->isAssetCurrent($this->imageAsset));
    }

    public function testIsAssetCurrentReturnsTrueWhenMetadataMatches(): void
    {
        // Use an asset that has real metadata (from createImageAssetWithFile)
        $asset = $this->createImageAssetWithFile('current-test.jpg');
        $assetId = (int)$asset->id;

        $this->service->saveHashForAsset($asset, 'someHash', null);

        $this->assertTrue($this->service->isAssetCurrent($asset, false));
    }

    public function testIsAssetCurrentReturnsFalseWhenSourceModifiedAtDiffers(): void
    {
        $this->assertIsAssetCurrentFalseWhenStoredMetadataFieldDiffers('sourceModifiedAt');
    }

    public function testIsAssetCurrentReturnsFalseWhenSourceSizeDiffers(): void
    {
        $this->assertIsAssetCurrentFalseWhenStoredMetadataFieldDiffers('sourceSize');
    }

    public function testIsAssetCurrentReturnsFalseWhenSourceWidthDiffers(): void
    {
        $this->assertIsAssetCurrentFalseWhenStoredMetadataFieldDiffers('sourceWidth');
    }

    public function testIsAssetCurrentReturnsFalseWhenSourceHeightDiffers(): void
    {
        $this->assertIsAssetCurrentFalseWhenStoredMetadataFieldDiffers('sourceHeight');
    }

    public function testIsAssetCurrentRequiresDataUrlWhenConfigured(): void
    {
        $asset = $this->createImageAssetWithFile('dataurl-test.jpg');

        // Save hash without data URL, using saveHashForAsset to capture metadata
        $this->service->saveHashForAsset($asset, 'someHash', null);

        // With requireDataUrl = true, should be false (no dataUrl stored)
        $this->assertFalse($this->service->isAssetCurrent($asset, true));
        // With requireDataUrl = false, should be true (hash + metadata match)
        $this->assertTrue($this->service->isAssetCurrent($asset, false));
    }

    // ── preloadRecordsForAssets ──────────────────────────────────────

    public function testPreloadRecordsForAssets(): void
    {
        $this->service->saveHash($this->imageAsset->id, 'hash1');
        $this->service->saveHash($this->secondImageAsset->id, 'hash2');

        $records = $this->service->preloadRecordsForAssets([
            $this->imageAsset->id,
            $this->secondImageAsset->id,
        ]);

        $this->assertCount(2, $records);
        $this->assertArrayHasKey($this->imageAsset->id, $records);
        $this->assertArrayHasKey($this->secondImageAsset->id, $records);
        $this->assertSame('hash1', $records[$this->imageAsset->id]->hash);
        $this->assertSame('hash2', $records[$this->secondImageAsset->id]->hash);
    }

    public function testPreloadRecordsForAssetsEmptyInput(): void
    {
        $this->assertSame([], $this->service->preloadRecordsForAssets([]));
    }

    // ── hashToDataUrl ───────────────────────────────────────────────

    public function testHashToDataUrl(): void
    {
        $hash = '3wcKNxqAh4eAiIiIiHiIiIiHCAiIgI8I';

        $dataUrl = $this->service->hashToDataUrl($hash);

        $this->assertStringStartsWith('data:image/png;base64,', $dataUrl);
    }

    public function testHashToDataUrlWithTargetRatio(): void
    {
        $hash = '3wcKNxqAh4eAiIiIiHiIiIiHCAiIgI8I';

        $dataUrl = $this->service->hashToDataUrl($hash, 16.0 / 9.0);

        $this->assertStringStartsWith('data:image/png;base64,', $dataUrl);
    }

    // ── Settings-driven helpers ─────────────────────────────────────

    public function testShouldGenerateDataUrl(): void
    {
        $this->setSettings(['generateDataUrl' => true]);
        $this->assertTrue($this->service->shouldGenerateDataUrl());

        $this->setSettings(['generateDataUrl' => false]);
        $this->assertFalse($this->service->shouldGenerateDataUrl());
    }

    public function testGetSourceTransformDefinition(): void
    {
        $transform = $this->service->getSourceTransformDefinition();

        $this->assertIsArray($transform);
        $this->assertArrayHasKey('mode', $transform);
        $this->assertArrayHasKey('width', $transform);
    }

    public function testTransformFetchMaxBytesRespectsMinimum(): void
    {
        // Set to something below the minimum (262144)
        $this->setSettings(['transformFetchMaxBytes' => 100]);

        $this->assertGreaterThanOrEqual(262144, $this->service->transformFetchMaxBytes());
    }

    public function testPngCompressionLevelClamped(): void
    {
        $this->setSettings(['pngCompressionLevel' => 99]);
        $this->assertSame(9, $this->service->pngCompressionLevel());

        $this->setSettings(['pngCompressionLevel' => -5]);
        $this->assertSame(0, $this->service->pngCompressionLevel());
    }

    // ── saveHashForAsset ────────────────────────────────────────────

    public function testSaveHashForAssetStoresSourceMetadata(): void
    {
        $asset = $this->createImageAssetWithFile('save-hash-for-asset-test.jpg');

        $this->service->saveHashForAsset($asset, 'testHash', 'testUrl');

        $record = ThumbhashRecord::findOne(['assetId' => $asset->id]);

        $this->assertNotNull($record);
        $this->assertSame('testHash', $record->hash);
        $this->assertSame('testUrl', $record->dataUrl);
        $this->assertEquals($asset->dateModified->getTimestamp(), $record->sourceModifiedAt);
        $this->assertEquals($asset->size, $record->sourceSize);
        $this->assertEquals($asset->width, $record->sourceWidth);
        $this->assertEquals($asset->height, $record->sourceHeight);
    }

    // ── generateHashPayloadFromFetchedFile ──────────────────────────

    public function testGenerateHashPayloadFromFetchedFile(): void
    {
        $asset = $this->createImageAssetWithFile('fetched-test.jpg');
        $imagePath = $this->createTestJpeg('fetched-file.jpg');

        $result = $this->service->generateHashPayloadFromFetchedFile($asset, $imagePath, true);

        $this->assertSame('ready', $result['status']);
        $this->assertNull($result['reason']);
        $this->assertNotNull($result['payload']);
        $this->assertNotEmpty($result['payload']['hash']);
        $this->assertStringStartsWith('data:image/png;base64,', $result['payload']['dataUrl']);

        @unlink($imagePath);
    }

    public function testGenerateHashPayloadFromFetchedFileWithMissingFile(): void
    {
        $asset = $this->createImageAssetWithFile('missing-test.jpg');

        $result = $this->service->generateHashPayloadFromFetchedFile(
            $asset,
            '/tmp/nonexistent-' . StringHelper::randomString(16) . '.jpg',
            false,
        );

        $this->assertSame('failed', $result['status']);
        $this->assertSame('extract_failed', $result['reason']);
        $this->assertNull($result['payload']);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function setSettings(array $overrides): void
    {
        /** @var Settings $settings */
        $settings = $this->plugin->getSettings();
        foreach ($overrides as $key => $value) {
            $settings->$key = $value;
        }
    }

    private function assertIsAssetCurrentFalseWhenStoredMetadataFieldDiffers(string $field): void
    {
        $asset = $this->createImageAssetWithFile("current-mismatch-{$field}.jpg");
        $this->service->saveHashForAsset($asset, 'someHash', null);

        $record = ThumbhashRecord::findOne(['assetId' => $asset->id]);
        $this->assertNotNull($record);
        $this->assertNotNull($record->$field);

        $record->$field = match ($field) {
            'sourceModifiedAt' => ((int)$record->$field) + 60,
            'sourceSize', 'sourceWidth', 'sourceHeight' => ((int)$record->$field) + 1,
            default => throw new \InvalidArgumentException("Unsupported metadata field: {$field}"),
        };

        $saved = $record->save(false);
        $this->assertTrue($saved);

        $this->assertFalse($this->service->isAssetCurrent($asset, false));
    }

    private function createFixtures(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/craft-thumbhash-svc-test-' . StringHelper::randomString(8);
        @mkdir($this->tempDir . '/files', 0777, true);

        $this->createVolumeAndFolder();
        $this->createAssets();
    }

    private function createVolumeAndFolder(): void
    {
        $fs = new \craft\fs\Local();
        $fs->name = 'Test FS';
        $fs->handle = 'testFs';
        $fs->path = $this->tempDir . '/files';
        $fs->hasUrls = true;
        $fs->url = 'http://test.craftcms.test/files';
        Craft::$app->getFs()->saveFilesystem($fs);

        $this->volume = new Volume();
        $this->volume->name = 'Test Volume';
        $this->volume->handle = 'testVolume';
        $this->volume->setFsHandle('testFs');
        Craft::$app->getVolumes()->saveVolume($this->volume);

        $this->rootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($this->volume->id);
    }

    private function createAssets(): void
    {
        $this->imageAsset = $this->createAsset('image.jpg', Asset::KIND_IMAGE);
        $this->secondImageAsset = $this->createAsset('photo.jpg', Asset::KIND_IMAGE);
        $this->svgAsset = $this->createSvgAsset('icon.svg');
        $this->pdfAsset = $this->createAsset('document.pdf', 'pdf');
    }

    private function createAsset(string $filename, string $kind): Asset
    {
        $asset = new Asset();
        $asset->volumeId = $this->volume->id;
        $asset->folderId = $this->rootFolder->id;
        $asset->filename = $filename;
        $asset->kind = $kind;
        $asset->title = pathinfo($filename, PATHINFO_FILENAME);
        $asset->setScenario(Asset::SCENARIO_CREATE);

        $saved = Craft::$app->getElements()->saveElement($asset, false);
        $this->assertTrue($saved, 'Failed to save asset: ' . implode(', ', $asset->getErrorSummary(true)));

        return $asset;
    }

    private function createSvgAsset(string $filename): Asset
    {
        $asset = new Asset();
        $asset->volumeId = $this->volume->id;
        $asset->folderId = $this->rootFolder->id;
        $asset->filename = $filename;
        $asset->kind = Asset::KIND_IMAGE;
        $asset->title = pathinfo($filename, PATHINFO_FILENAME);
        $asset->setScenario(Asset::SCENARIO_CREATE);

        $saved = Craft::$app->getElements()->saveElement($asset, false);
        $this->assertTrue($saved, 'Failed to save asset: ' . implode(', ', $asset->getErrorSummary(true)));

        return $asset;
    }

    /**
     * Create an asset with a real JPEG file on disk so hash generation works.
     */
    private function createImageAssetWithFile(string $filename): Asset
    {
        $imagePath = $this->createTestJpeg($filename);

        $asset = new Asset();
        $asset->volumeId = $this->volume->id;
        $asset->folderId = $this->rootFolder->id;
        $asset->filename = $filename;
        $asset->kind = Asset::KIND_IMAGE;
        $asset->title = pathinfo($filename, PATHINFO_FILENAME);
        $asset->size = filesize($imagePath);
        $asset->width = 200;
        $asset->height = 150;
        $asset->dateModified = new \DateTime();
        $asset->setScenario(Asset::SCENARIO_CREATE);

        $saved = Craft::$app->getElements()->saveElement($asset, false);
        $this->assertTrue($saved, 'Failed to save asset: ' . implode(', ', $asset->getErrorSummary(true)));

        return $asset;
    }

    /**
     * Create a small test JPEG image on disk.
     */
    private function createTestJpeg(string $filename): string
    {
        $path = $this->tempDir . '/files/' . $filename;
        $img = imagecreatetruecolor(200, 150);
        // Paint a gradient so the hash is non-trivial
        for ($y = 0; $y < 150; $y++) {
            for ($x = 0; $x < 200; $x++) {
                $color = imagecolorallocate($img, (int)($x * 255 / 200), (int)($y * 255 / 150), 128);
                imagesetpixel($img, $x, $y, $color);
            }
        }
        imagejpeg($img, $path, 90);
        imagedestroy($img);

        return $path;
    }
}
