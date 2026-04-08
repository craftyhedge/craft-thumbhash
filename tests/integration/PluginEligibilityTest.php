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

class PluginEligibilityTest extends Unit
{
    private Plugin $plugin;

    private Volume $uploadsVolume;
    private Volume $mediaVolume;

    private VolumeFolder $uploadsRoot;
    private VolumeFolder $photosFolder;
    private VolumeFolder $tempFolder;
    private VolumeFolder $mediaRoot;
    private VolumeFolder $galleryFolder;

    private Asset $uploadsRootAsset;
    private Asset $photosAsset;
    private Asset $tempAsset;
    private Asset $mediaRootAsset;
    private Asset $galleryAsset;

    protected function _before(): void
    {
        parent::_before();

        $this->plugin = Plugin::getInstance();

        // Reset settings to defaults before each test
        $this->setSettings([
            'volumes' => '*',
            'includeRules' => [],
            'ignoreRules' => [],
            'autoGenerate' => false,
        ]);

        $this->createFixtures();
    }

    // ── isVolumeAllowed ─────────────────────────────────────────────

    public function testIsVolumeAllowedWildcard(): void
    {
        $this->setSettings(['volumes' => '*']);

        $this->assertTrue($this->plugin->isVolumeAllowed($this->uploadsRootAsset));
        $this->assertTrue($this->plugin->isVolumeAllowed($this->mediaRootAsset));
    }

    public function testIsVolumeAllowedNull(): void
    {
        $this->setSettings(['volumes' => null]);

        $this->assertTrue($this->plugin->isVolumeAllowed($this->uploadsRootAsset));
        $this->assertTrue($this->plugin->isVolumeAllowed($this->mediaRootAsset));
    }

    public function testIsVolumeAllowedSpecificVolume(): void
    {
        $this->setSettings(['volumes' => ['uploads']]);

        $this->assertTrue($this->plugin->isVolumeAllowed($this->uploadsRootAsset));
        $this->assertFalse($this->plugin->isVolumeAllowed($this->mediaRootAsset));
    }

    public function testIsVolumeAllowedMultipleVolumes(): void
    {
        $this->setSettings(['volumes' => ['uploads', 'media']]);

        $this->assertTrue($this->plugin->isVolumeAllowed($this->uploadsRootAsset));
        $this->assertTrue($this->plugin->isVolumeAllowed($this->mediaRootAsset));
    }

    public function testIsVolumeAllowedEmptyArray(): void
    {
        $this->setSettings(['volumes' => []]);

        $this->assertFalse($this->plugin->isVolumeAllowed($this->uploadsRootAsset));
        $this->assertFalse($this->plugin->isVolumeAllowed($this->mediaRootAsset));
    }

    // ── isAssetIgnoredByRules ───────────────────────────────────────

    public function testIsAssetIgnoredNoRules(): void
    {
        $this->setSettings(['ignoreRules' => []]);

        $this->assertFalse($this->plugin->isAssetIgnoredByRules($this->uploadsRootAsset));
        $this->assertFalse($this->plugin->isAssetIgnoredByRules($this->photosAsset));
    }

    public function testIsAssetIgnoredGlobalRule(): void
    {
        $this->setSettings(['ignoreRules' => ['*' => ['temp/*']]]);

        $this->assertFalse($this->plugin->isAssetIgnoredByRules($this->uploadsRootAsset));
        $this->assertFalse($this->plugin->isAssetIgnoredByRules($this->photosAsset));
        $this->assertTrue($this->plugin->isAssetIgnoredByRules($this->tempAsset));
        $this->assertFalse($this->plugin->isAssetIgnoredByRules($this->galleryAsset));
    }

    public function testIsAssetIgnoredScopedRule(): void
    {
        $this->setSettings(['ignoreRules' => ['uploads' => ['temp/*']]]);

        $this->assertFalse($this->plugin->isAssetIgnoredByRules($this->uploadsRootAsset));
        $this->assertTrue($this->plugin->isAssetIgnoredByRules($this->tempAsset));
        // media volume is not affected by uploads-scoped rule
        $this->assertFalse($this->plugin->isAssetIgnoredByRules($this->mediaRootAsset));
    }

    public function testIsAssetIgnoredWildcardPattern(): void
    {
        $this->setSettings(['ignoreRules' => ['*' => ['*']]]);

        // Root-folder assets have NULL path in volumefolders, so
        // folderPath('*') (LIKE '%') does not match them.
        $this->assertFalse($this->plugin->isAssetIgnoredByRules($this->uploadsRootAsset));
        $this->assertTrue($this->plugin->isAssetIgnoredByRules($this->photosAsset));
        $this->assertFalse($this->plugin->isAssetIgnoredByRules($this->mediaRootAsset));
    }

    // ── isAssetIncludedByRules ──────────────────────────────────────

    public function testIsAssetIncludedNoRules(): void
    {
        $this->setSettings(['includeRules' => []]);

        // No include rules = everything included
        $this->assertTrue($this->plugin->isAssetIncludedByRules($this->uploadsRootAsset));
        $this->assertTrue($this->plugin->isAssetIncludedByRules($this->photosAsset));
        $this->assertTrue($this->plugin->isAssetIncludedByRules($this->mediaRootAsset));
    }

    public function testIsAssetIncludedGlobalRule(): void
    {
        $this->setSettings(['includeRules' => ['*' => ['photos/*']]]);

        $this->assertFalse($this->plugin->isAssetIncludedByRules($this->uploadsRootAsset));
        $this->assertTrue($this->plugin->isAssetIncludedByRules($this->photosAsset));
        $this->assertFalse($this->plugin->isAssetIncludedByRules($this->tempAsset));
        $this->assertFalse($this->plugin->isAssetIncludedByRules($this->galleryAsset));
    }

    public function testIsAssetIncludedScopedRule(): void
    {
        $this->setSettings(['includeRules' => ['uploads' => ['photos/*']]]);

        // uploads volume: only photos/ included
        $this->assertFalse($this->plugin->isAssetIncludedByRules($this->uploadsRootAsset));
        $this->assertTrue($this->plugin->isAssetIncludedByRules($this->photosAsset));
        $this->assertFalse($this->plugin->isAssetIncludedByRules($this->tempAsset));
        // media volume: no scoped rules for it, so all included
        $this->assertTrue($this->plugin->isAssetIncludedByRules($this->mediaRootAsset));
        $this->assertTrue($this->plugin->isAssetIncludedByRules($this->galleryAsset));
    }

    // ── isAssetAllowed ──────────────────────────────────────────────

    public function testIsAssetAllowedCombinesAllChecks(): void
    {
        $this->setSettings([
            'volumes' => ['uploads'],
            'includeRules' => ['uploads' => ['photos/*']],
            'ignoreRules' => [],
        ]);

        // uploads/photos: allowed volume + included path
        $this->assertTrue($this->plugin->isAssetAllowed($this->photosAsset));
        // uploads/root: allowed volume but not in include path
        $this->assertFalse($this->plugin->isAssetAllowed($this->uploadsRootAsset));
        // media: volume not allowed
        $this->assertFalse($this->plugin->isAssetAllowed($this->mediaRootAsset));
    }

    public function testIsAssetAllowedIgnoreOverridesInclude(): void
    {
        $this->setSettings([
            'volumes' => '*',
            'includeRules' => [],
            'ignoreRules' => ['*' => ['photos/*']],
        ]);

        // photos/ is ignored even though no include rules restrict it
        $this->assertFalse($this->plugin->isAssetAllowed($this->photosAsset));
        $this->assertTrue($this->plugin->isAssetAllowed($this->uploadsRootAsset));
        $this->assertTrue($this->plugin->isAssetAllowed($this->galleryAsset));
    }

    // ── applyIgnoreRulesToQuery ─────────────────────────────────────

    public function testBaseQueryReturnsAllAssets(): void
    {
        $query = $this->buildBaseAssetQuery();
        $results = $query->column();
        $resultIds = array_map('intval', $results);

        $this->assertCount(5, $resultIds);
        $this->assertContains($this->uploadsRootAsset->id, $resultIds);
        $this->assertContains($this->photosAsset->id, $resultIds);
        $this->assertContains($this->tempAsset->id, $resultIds);
        $this->assertContains($this->mediaRootAsset->id, $resultIds);
        $this->assertContains($this->galleryAsset->id, $resultIds);
    }

    public function testApplyIgnoreRulesToQueryNoRules(): void
    {
        $this->setSettings(['ignoreRules' => []]);

        $query = $this->buildBaseAssetQuery();
        $beforeSql = $query->createCommand()->getRawSql();

        $this->plugin->applyIgnoreRulesToQuery($query);

        // Query should be unchanged when there are no rules
        $this->assertSame($beforeSql, $query->createCommand()->getRawSql());
    }

    public function testApplyIgnoreRulesToQueryGlobalPattern(): void
    {
        $this->setSettings(['ignoreRules' => ['*' => ['temp/*']]]);

        $query = $this->buildBaseAssetQuery();
        $this->plugin->applyIgnoreRulesToQuery($query);

        $results = $query->column();
        $resultIds = array_map('intval', $results);

        $this->assertContains($this->uploadsRootAsset->id, $resultIds);
        $this->assertContains($this->photosAsset->id, $resultIds);
        $this->assertNotContains($this->tempAsset->id, $resultIds);
        $this->assertContains($this->mediaRootAsset->id, $resultIds);
        $this->assertContains($this->galleryAsset->id, $resultIds);
    }

    public function testApplyIgnoreRulesToQueryScopedPattern(): void
    {
        $this->setSettings(['ignoreRules' => ['uploads' => ['temp/*']]]);

        $query = $this->buildBaseAssetQuery();
        $this->plugin->applyIgnoreRulesToQuery($query);

        $results = $query->column();
        $resultIds = array_map('intval', $results);

        // Root-folder assets preserved via OR path IS NULL fallback
        $this->assertContains($this->uploadsRootAsset->id, $resultIds);
        $this->assertNotContains($this->tempAsset->id, $resultIds);
        $this->assertContains($this->mediaRootAsset->id, $resultIds);
        $this->assertContains($this->galleryAsset->id, $resultIds);
    }

    // ── applyIncludeRulesToQuery ────────────────────────────────────

    public function testApplyIncludeRulesToQueryNoRules(): void
    {
        $this->setSettings(['includeRules' => []]);

        $query = $this->buildBaseAssetQuery();
        $beforeSql = $query->createCommand()->getRawSql();

        $this->plugin->applyIncludeRulesToQuery($query);

        $this->assertSame($beforeSql, $query->createCommand()->getRawSql());
    }

    public function testApplyIncludeRulesToQueryGlobalPattern(): void
    {
        $this->setSettings(['includeRules' => ['*' => ['photos/*']]]);

        $query = $this->buildBaseAssetQuery();
        $this->plugin->applyIncludeRulesToQuery($query);

        $results = $query->column();
        $resultIds = array_map('intval', $results);

        $this->assertContains($this->photosAsset->id, $resultIds);
        $this->assertNotContains($this->uploadsRootAsset->id, $resultIds);
        $this->assertNotContains($this->tempAsset->id, $resultIds);
    }

    public function testApplyIncludeRulesToQueryScopedPattern(): void
    {
        $this->setSettings(['includeRules' => ['uploads' => ['photos/*']]]);

        $query = $this->buildBaseAssetQuery();
        $this->plugin->applyIncludeRulesToQuery($query);

        $results = $query->column();
        $resultIds = array_map('intval', $results);

        // uploads: only photos/ included
        $this->assertContains($this->photosAsset->id, $resultIds);
        $this->assertNotContains($this->uploadsRootAsset->id, $resultIds);
        $this->assertNotContains($this->tempAsset->id, $resultIds);
        // media: not scoped, so all remain
        $this->assertContains($this->mediaRootAsset->id, $resultIds);
        $this->assertContains($this->galleryAsset->id, $resultIds);
    }

    // ── applyFolderRulesToQuery ─────────────────────────────────────

    public function testApplyFolderRulesToQueryCombined(): void
    {
        $this->setSettings([
            'includeRules' => ['uploads' => ['photos/*', 'temp/*']],
            'ignoreRules' => ['*' => ['temp/*']],
        ]);

        $query = $this->buildBaseAssetQuery();
        $this->plugin->applyFolderRulesToQuery($query);

        $results = $query->column();
        $resultIds = array_map('intval', $results);

        // uploads/photos: included and not ignored
        $this->assertContains($this->photosAsset->id, $resultIds);
        // uploads/temp: included but also ignored → excluded
        $this->assertNotContains($this->tempAsset->id, $resultIds);
        // uploads/root: not in include list for uploads scope
        $this->assertNotContains($this->uploadsRootAsset->id, $resultIds);
        // media: not scoped by include rules, so all remain; root assets
        // preserved by OR path IS NULL fallback in ignore query.
        $this->assertContains($this->mediaRootAsset->id, $resultIds);
        $this->assertContains($this->galleryAsset->id, $resultIds);
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

    /**
     * Build a base query that mimics what the plugin's batch job uses:
     * selects asset IDs joined with volumeFolders and volumes.
     */
    private function buildBaseAssetQuery(): \craft\db\Query
    {
        return (new \craft\db\Query())
            ->select(['assets.id'])
            ->from(['assets' => '{{%assets}}'])
            ->innerJoin(['volumeFolders' => '{{%volumefolders}}'], '[[volumeFolders.id]] = [[assets.folderId]]')
            ->innerJoin(['volumes' => '{{%volumes}}'], '[[volumes.id]] = [[assets.volumeId]]');
    }

    private function createFixtures(): void
    {
        $this->createVolumes();
        $this->createFolders();
        $this->createAssets();
    }

    private function createVolumes(): void
    {
        // Create temp directories for Local filesystems
        $basePath = sys_get_temp_dir() . '/craft-thumbhash-test-' . StringHelper::randomString(8);
        @mkdir($basePath . '/uploads', 0777, true);
        @mkdir($basePath . '/media', 0777, true);

        // Create filesystems
        $uploadsFs = new \craft\fs\Local();
        $uploadsFs->name = 'Uploads FS';
        $uploadsFs->handle = 'uploadsFs';
        $uploadsFs->path = $basePath . '/uploads';
        $uploadsFs->hasUrls = true;
        $uploadsFs->url = 'http://test.craftcms.test/uploads';
        Craft::$app->getFs()->saveFilesystem($uploadsFs);

        $mediaFs = new \craft\fs\Local();
        $mediaFs->name = 'Media FS';
        $mediaFs->handle = 'mediaFs';
        $mediaFs->path = $basePath . '/media';
        $mediaFs->hasUrls = true;
        $mediaFs->url = 'http://test.craftcms.test/media';
        Craft::$app->getFs()->saveFilesystem($mediaFs);

        // Create volumes
        $this->uploadsVolume = new Volume();
        $this->uploadsVolume->name = 'Uploads';
        $this->uploadsVolume->handle = 'uploads';
        $this->uploadsVolume->setFsHandle('uploadsFs');
        Craft::$app->getVolumes()->saveVolume($this->uploadsVolume);

        $this->mediaVolume = new Volume();
        $this->mediaVolume->name = 'Media';
        $this->mediaVolume->handle = 'media';
        $this->mediaVolume->setFsHandle('mediaFs');
        Craft::$app->getVolumes()->saveVolume($this->mediaVolume);
    }

    private function createFolders(): void
    {
        // Get root folders (created automatically by saveVolume)
        $this->uploadsRoot = Craft::$app->getAssets()->getRootFolderByVolumeId($this->uploadsVolume->id);
        $this->mediaRoot = Craft::$app->getAssets()->getRootFolderByVolumeId($this->mediaVolume->id);

        // Create subfolders
        $this->photosFolder = $this->createFolder($this->uploadsVolume, $this->uploadsRoot, 'photos', 'photos/');
        $this->tempFolder = $this->createFolder($this->uploadsVolume, $this->uploadsRoot, 'temp', 'temp/');
        $this->galleryFolder = $this->createFolder($this->mediaVolume, $this->mediaRoot, 'gallery', 'gallery/');
    }

    private function createFolder(Volume $volume, VolumeFolder $parent, string $name, string $path): VolumeFolder
    {
        $folder = new VolumeFolder();
        $folder->name = $name;
        $folder->volumeId = $volume->id;
        $folder->parentId = $parent->id;
        $folder->path = $path;

        Craft::$app->getAssets()->createFolder($folder);

        return $folder;
    }

    private function createAssets(): void
    {
        $this->uploadsRootAsset = $this->createAsset($this->uploadsVolume, $this->uploadsRoot, 'root-image.jpg');
        $this->photosAsset = $this->createAsset($this->uploadsVolume, $this->photosFolder, 'photo.jpg');
        $this->tempAsset = $this->createAsset($this->uploadsVolume, $this->tempFolder, 'temp-image.jpg');
        $this->mediaRootAsset = $this->createAsset($this->mediaVolume, $this->mediaRoot, 'media-image.jpg');
        $this->galleryAsset = $this->createAsset($this->mediaVolume, $this->galleryFolder, 'gallery-image.jpg');
    }

    private function createAsset(Volume $volume, VolumeFolder $folder, string $filename): Asset
    {
        $asset = new Asset();
        $asset->volumeId = $volume->id;
        $asset->folderId = $folder->id;
        $asset->filename = $filename;
        $asset->kind = Asset::KIND_IMAGE;
        $asset->title = pathinfo($filename, PATHINFO_FILENAME);
        $asset->setScenario(Asset::SCENARIO_CREATE);

        $saved = Craft::$app->getElements()->saveElement($asset, false);
        $this->assertTrue($saved, 'Failed to save asset: ' . implode(', ', $asset->getErrorSummary(true)));

        return $asset;
    }
}
