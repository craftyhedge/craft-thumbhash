<?php

namespace craftyhedge\craftthumbhash\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\Query;
use craft\db\QueryBatcher;
use craft\db\Table as CraftTable;
use craft\elements\Asset;
use craft\helpers\ConfigHelper;
use craft\helpers\Db;
use craft\helpers\Queue as QueueHelper;
use craft\i18n\Translation;
use craft\queue\BaseBatchedJob;
use craft\queue\Queue as CraftQueue;
use craftyhedge\craftthumbhash\db\Table as PluginTable;
use craftyhedge\craftthumbhash\Plugin;
use craftyhedge\craftthumbhash\services\ThumbhashService;
use samdark\log\PsrMessage;
use yii\db\Expression;
use yii\log\Logger;

class GenerateThumbhashBatch extends BaseBatchedJob
{
    private const LOG_CATEGORY = 'thumbhash';
    private const RUN_CACHE_KEY = 'thumbhash:utility:run:global';
    private const RUN_FAILURE_MESSAGE = 'Some images could not be processed. They remain eligible for a future run. Check the ThumbHash logs for details.';

    /**
     * @var array<string>|string|null
     */
    public array|string|null $volumes = null;
    public int $scanned = 0;
    public int $skippedCurrent = 0;
    public int $generated = 0;
    public int $failed = 0;

    protected function before(): void
    {
        parent::before();

        $this->scanned = 0;
        $this->skippedCurrent = 0;
        $this->generated = 0;
        $this->failed = 0;
    }

    protected function after(): void
    {
        parent::after();

        $this->logEvent('info', 'thumbhash.batch.summary', [
            'scanned' => $this->scanned,
            'skippedCurrent' => $this->skippedCurrent,
            'generated' => $this->generated,
            'failed' => $this->failed,
            'volumes' => $this->volumes,
        ]);
    }

    protected function loadData(): Batchable
    {
        // Keep the batch query stable. Filtering by staleness here would mutate the
        // result set as rows are saved, and offset-based slices can skip remaining items.
        $query = Asset::find()
            ->status(null)
            ->site('*')
            ->unique(true)
            ->kind(Asset::KIND_IMAGE)
            ->filename(['not', '*.svg'])
            ->orderBy('elements.id ASC');

        if ($this->volumes !== null && $this->volumes !== '*') {
            $query->volume((array)$this->volumes);
        }

        $plugin = Plugin::getInstance();
        if ($plugin !== null) {
            $plugin->applyFolderRulesToQuery(
                $query,
                folderPathColumn: 'volumeFolders.path',
                volumeHandleColumn: null,
                volumeIdColumn: 'assets.volumeId',
            );
        }

        return new QueryBatcher($query);
    }

    /**
     * Override execution so utility status can follow spawned batch jobs.
     */
    public function execute($queue): void
    {
        $items = $this->data()->getSlice($this->itemOffset, $this->batchSize);

        $memoryLimit = ConfigHelper::sizeInBytes(ini_get('memory_limit'));
        $startMemory = $memoryLimit != -1 ? memory_get_usage() : null;
        $start = microtime(true);

        if ($this->itemOffset === 0) {
            $this->before();
        }

        $this->beforeBatch();

        // Report current position immediately so continuation jobs don't
        // show 0% while the first prefetch blocks on transform generation.
        $this->reportProgress($queue);

        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return;
        }

        $service = $plugin->thumbhash;
        $generateDataUrl = $service->shouldGenerateDataUrl();
        $concurrency = $service->fetchConcurrency();

        // Batch-load existing thumbhash records for the entire slice
        // to avoid N+1 queries during eligibility checks.
        $assetIds = [];
        foreach ($items as $item) {
            if ($item instanceof Asset) {
                $assetIds[] = (int)$item->id;
            }
        }
        $preloadedRecords = $service->preloadRecordsForAssets($assetIds);

        // Partition items into eligible and ineligible
        /** @var Asset[] $eligible */
        $eligible = [];
        /** @var array<int, Asset|mixed> $ineligible */
        $ineligible = [];

        foreach ($items as $item) {
            if ($this->isEligibleForGeneration($item, $plugin, $service, $generateDataUrl, $preloadedRecords)) {
                $eligible[] = $item;
            } else {
                $ineligible[] = $item;
            }
        }

        $i = 0;

        // Process ineligible items (advance offset + progress)
        foreach ($ineligible as $item) {
            $this->itemOffset++;
            $i++;
            $this->reportProgress($queue);
        }

        // Process eligible items in chunks via concurrent prefetch
        $chunks = array_chunk($eligible, $concurrency);

        foreach ($chunks as $chunk) {
            // Report progress before prefetch so the queue UI stays current
            // while transforms are being generated (can block for seconds).
            $this->reportProgress($queue);

            $prefetched = [];
            try {
                $prefetched = $service->prefetchTransforms($chunk);

                foreach ($chunk as $asset) {
                    $assetId = (int)$asset->id;
                    $fetchResult = $prefetched[$assetId] ?? null;

                    if ($fetchResult !== null) {
                        $result = $service->generateHashPayloadFromFetchedFile(
                            $asset,
                            $fetchResult['path'],
                            $generateDataUrl,
                        );

                        $generated = $result['payload'];

                        if ($generated !== null) {
                            $this->generated++;
                            $service->saveHashForAsset($asset, $generated['hash'], $generated['dataUrl']);
                        } else {
                            $this->failed++;
                            $this->markUtilityRunFailed();
                        }
                    } else {
                        $this->failed++;
                        $this->markUtilityRunFailed();
                    }

                    $this->itemOffset++;
                    $i++;
                    $this->reportProgress($queue);
                }
            } finally {
                foreach ($prefetched as $fetchResult) {
                    $tempPath = $fetchResult['path'] ?? null;
                    if ($tempPath !== null && file_exists($tempPath)) {
                        @unlink($tempPath);
                    }
                }
            }

            // Memory guard between chunks
            if ($startMemory !== null) {
                $memory = memory_get_usage();
                $avgMemory = ($memory - $startMemory) / $i;
                if ($memory + ($avgMemory * 15) > $memoryLimit) {
                    break;
                }
            }

            // TTR guard between chunks
            $runningTime = microtime(true) - $start;
            $avgRunningTime = $runningTime / $i;
            if ($this->ttr !== null && $runningTime + ($avgRunningTime * 2) > $this->ttr) {
                break;
            }

            // Queue reservation check
            if ($queue instanceof CraftQueue && !$queue->isReserved($queue->getJobId())) {
                return;
            }
        }

        $this->afterBatch();

        if ($this->itemOffset < $this->totalItems()) {
            $nextJob = clone $this;
            $nextJob->batchIndex++;
            $nextJobId = QueueHelper::push($nextJob, $this->priority, 0, $this->ttr, $queue);
            $this->advanceUtilityRunJobId($nextJobId);
            return;
        }

        $this->after();
    }

    public function staleAssetCount(): int
    {
        $service = Plugin::getInstance()->thumbhash;
        $requireDataUrl = $service->shouldGenerateDataUrl();

        return (int)$this->staleAssetIdQuery($requireDataUrl)->count();
    }

    protected function processItem(mixed $item): void
    {
        if (!$item instanceof Asset) {
            return;
        }

        $asset = $item;

        $this->scanned++;

        if (strtolower($asset->getExtension()) === 'svg') {
            return;
        }

        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return;
        }

        if (!$plugin->isAssetAllowed($asset)) {
            return;
        }

        $service = $plugin->thumbhash;
        $generateDataUrl = $service->shouldGenerateDataUrl();

        if ($service->isAssetCurrent($asset, $generateDataUrl)) {
            $this->skippedCurrent++;
            return;
        }

        $result = $service->generateHashPayloadWithStatus($asset, $generateDataUrl);

        $generated = $result['payload'];

        if ($generated !== null) {
            $this->generated++;
            $service->saveHashForAsset($asset, $generated['hash'], $generated['dataUrl']);
            return;
        }

        $this->failed++;
        $this->markUtilityRunFailed();
    }

    protected function defaultDescription(): ?string
    {
        return 'ThumbHash: Batch generating image placeholders';
    }

    /**
     * Check whether an item is an eligible asset for hash generation.
     *
     * Used by execute() to partition items before chunked prefetch.
     *
     * Returns true only when the asset needs a new/updated hash.
     * Side-effects: increments $this->scanned, $this->skippedCurrent as appropriate.
     */
    /**
     * @param array<int, \craftyhedge\craftthumbhash\records\ThumbhashRecord> $preloadedRecords
     */
    private function isEligibleForGeneration(
        mixed $item,
        Plugin $plugin,
        ThumbhashService $service,
        bool $generateDataUrl,
        array $preloadedRecords = [],
    ): bool {
        $this->scanned++;

        if (!$item instanceof Asset) {
            return false;
        }

        if (strtolower($item->getExtension()) === 'svg') {
            return false;
        }

        if (!$plugin->isAssetAllowed($item)) {
            return false;
        }

        $record = $preloadedRecords[(int)$item->id] ?? null;
        if ($service->isAssetCurrentWithRecord($item, $record, $generateDataUrl)) {
            $this->skippedCurrent++;
            return false;
        }

        return true;
    }

    private function reportProgress(mixed $queue): void
    {
        $step = $this->itemOffset;
        $total = $this->totalItems();

        if ($total > 0) {
            $this->setProgress($queue, $step / $total, Translation::prep('app', '{step, number} of {total, number}', [
                'step' => $step,
                'total' => $total,
            ]));
        }
    }

    private function logEvent(string $level, string $event, array $context = []): void
    {
        $message = new PsrMessage($event, $context);

        if ($level === 'warning') {
            Craft::warning($message, self::LOG_CATEGORY);
            return;
        }

        if ($level === 'error') {
            Craft::error($message, self::LOG_CATEGORY);
            return;
        }

        if ($level === 'debug') {
            Craft::getLogger()->log($message, Logger::LEVEL_TRACE, self::LOG_CATEGORY);
            return;
        }

        Craft::info($message, self::LOG_CATEGORY);
    }

    private function markUtilityRunFailed(): void
    {
        $cache = Craft::$app->getCache();
        $run = $cache->get(self::RUN_CACHE_KEY);

        if (!is_array($run)) {
            return;
        }

        $run['hasFailures'] = true;
        $run['failureMessage'] ??= self::RUN_FAILURE_MESSAGE;

        $cache->set(self::RUN_CACHE_KEY, $run);
    }

    private function advanceUtilityRunJobId(mixed $jobId): void
    {
        if ($jobId === null || $jobId === '') {
            return;
        }

        $cache = Craft::$app->getCache();
        $run = $cache->get(self::RUN_CACHE_KEY);

        if (!is_array($run)) {
            return;
        }

        $run['jobId'] = (string)$jobId;
        $cache->set(self::RUN_CACHE_KEY, $run);
    }

    private function staleAssetIdQuery(bool $requireDataUrl): Query
    {
        $query = (new Query())
            ->select(['assets.id'])
            ->from(['assets' => CraftTable::ASSETS])
            ->innerJoin(['elements' => CraftTable::ELEMENTS], '[[elements.id]] = [[assets.id]]')
            ->innerJoin(['volumeFolders' => CraftTable::VOLUMEFOLDERS], '[[volumeFolders.id]] = [[assets.folderId]]')
            ->leftJoin(['thumbhashes' => PluginTable::THUMBHASHES], '[[thumbhashes.assetId]] = [[assets.id]]')
            ->where([
                'elements.dateDeleted' => null,
                'elements.archived' => false,
            ])
            ->andWhere(['assets.kind' => Asset::KIND_IMAGE])
            ->andWhere(Db::parseParam('assets.filename', ['not', '*.svg']))
            ->orderBy(['assets.id' => SORT_ASC]);

        $volumeIds = $this->resolveVolumeIds();
        if ($volumeIds === []) {
            $query->andWhere('0=1');
            return $query;
        }

        if ($volumeIds !== null) {
            $query->andWhere(['assets.volumeId' => $volumeIds]);
        }

        $plugin = Plugin::getInstance();
        if ($plugin !== null) {
            $plugin->applyFolderRulesToQuery(
                $query,
                folderPathColumn: 'volumeFolders.path',
                volumeHandleColumn: null,
                volumeIdColumn: 'assets.volumeId',
            );
        }

        $staleConditions = [
            'or',
            ['thumbhashes.id' => null],
            ['thumbhashes.hash' => null],
            ['thumbhashes.hash' => ''],
            ['thumbhashes.sourceModifiedAt' => null],
            ['thumbhashes.sourceSize' => null],
            ['thumbhashes.sourceWidth' => null],
            ['thumbhashes.sourceHeight' => null],
            ['assets.dateModified' => null],
            ['assets.size' => null],
            ['assets.width' => null],
            ['assets.height' => null],
            new Expression('[[thumbhashes.sourceSize]] <> [[assets.size]]'),
            new Expression('[[thumbhashes.sourceWidth]] <> [[assets.width]]'),
            new Expression('[[thumbhashes.sourceHeight]] <> [[assets.height]]'),
            $this->sourceModifiedAtMismatchExpression(),
        ];

        if ($requireDataUrl) {
            $staleConditions[] = ['thumbhashes.dataUrl' => null];
            $staleConditions[] = ['thumbhashes.dataUrl' => ''];
        }

        $query->andWhere($staleConditions);

        return $query;
    }

    /**
     * @return array<int>|null
     */
    private function resolveVolumeIds(): ?array
    {
        if ($this->volumes === null || $this->volumes === '*') {
            return null;
        }

        $ids = [];
        $handles = [];

        foreach ((array)$this->volumes as $volume) {
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

        $ids = array_values(array_unique($ids));

        return $ids;
    }

    private function sourceModifiedAtMismatchExpression(): Expression
    {
        $db = Craft::$app->getDb();

        if ($db->getIsMysql()) {
            return new Expression("[[thumbhashes.sourceModifiedAt]] <> TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', [[assets.dateModified]])");
        }

        if ($db->getIsPgsql()) {
            return new Expression("[[thumbhashes.sourceModifiedAt]] <> CAST(EXTRACT(EPOCH FROM ([[assets.dateModified]] - TIMESTAMP '1970-01-01 00:00:00')) AS BIGINT)");
        }

        if ($db->getDriverName() === 'sqlite') {
            return new Expression("[[thumbhashes.sourceModifiedAt]] <> CAST((julianday([[assets.dateModified]]) - 2440587.5) * 86400 AS INTEGER)");
        }

        // Prefer correctness over skipping stale records on unknown drivers.
        return new Expression('1=1');
    }

}
