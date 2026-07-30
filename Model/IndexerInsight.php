<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Indexer\Model\Indexer\CollectionFactory;

/**
 * The two things the indexer list does not say: whether an index updates on save or on
 * schedule, and — when it is on schedule — how far behind it is.
 *
 * "Reindex finished but the storefront is still stale" is almost always a backlog: the
 * changelog has grown past what mview has consumed, and reindexAll() does not touch that.
 */
class IndexerInsight
{
    public function __construct(
        private readonly CollectionFactory $indexerCollectionFactory,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @return array<int, array{
     *     id: string, title: string, status: string, scheduled: bool,
     *     backlog: ?int, view_status: ?string, updated: ?string
     * }>
     */
    public function getIndexers(): array
    {
        $indexers = [];

        foreach ($this->indexerCollectionFactory->create()->getItems() as $indexer) {
            $scheduled = (bool) $indexer->isScheduled();
            $view      = $indexer->getView();

            $indexers[] = [
                'id'          => (string) $indexer->getId(),
                'title'       => (string) $indexer->getTitle(),
                'status'      => (string) $indexer->getStatus(),
                'scheduled'   => $scheduled,
                // A backlog only means anything for an index that is actually on schedule;
                // in realtime mode the changelog is not consumed and its size says nothing.
                'backlog'     => $scheduled ? $this->backlog($indexer->getId()) : null,
                'view_status' => $scheduled && $view ? (string) $view->getState()->getStatus() : null,
                'updated'     => $indexer->getLatestUpdated() ?: null,
            ];
        }

        return $indexers;
    }

    /**
     * Flip one indexer between "Update on Save" and "Update by Schedule".
     *
     * @throws LocalizedException
     */
    public function setMode(string $indexerId, bool $scheduled): string
    {
        $indexer = $this->indexerRegistry->get($indexerId);
        $indexer->setScheduled($scheduled);

        return $scheduled
            ? (string) __('%1 now updates by schedule — it will not change until cron runs.', $indexer->getTitle())
            : (string) __('%1 now updates on save.', $indexer->getTitle());
    }

    /**
     * How many changelog entries the view has not consumed yet.
     *
     * Read from the changelog table's own max version minus the version mview has
     * processed, which is the same arithmetic mview does — rather than counting rows,
     * which over-reports after a changelog has been trimmed.
     */
    private function backlog(string $indexerId): ?int
    {
        $connection = $this->resource->getConnection();
        $changelog  = $this->resource->getTableName($indexerId . '_cl');

        if (!$connection->isTableExists($changelog)) {
            return null;
        }

        try {
            $current = (int) $connection->fetchOne(
                $connection->select()->from($changelog, ['max' => new \Zend_Db_Expr('MAX(version_id)')])
            );

            $processed = (int) $connection->fetchOne(
                $connection->select()
                    ->from($this->resource->getTableName('mview_state'), ['version_id'])
                    ->where('view_id = ?', $indexerId)
            );

            return max(0, $current - $processed);
        } catch (\Exception $e) {
            return null;
        }
    }
}
