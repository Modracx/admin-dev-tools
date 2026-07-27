<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Message queue backlog, read from the MySQL queue tables.
 *
 * These tables only exist when the MySQL queue backend is in play. Stores running
 * AMQP/RabbitMQ keep their state in the broker instead, so isAvailable() reports false
 * and the panel says so rather than showing a misleading empty list.
 */
class QueueHealth
{
    private const TABLE_STATUS = 'queue_message_status';
    private const TABLE_QUEUE  = 'queue';

    /** @see \Magento\MysqlMq\Model\QueueManagement */
    private const STATUS_LABELS = [
        2 => 'New',
        3 => 'In progress',
        4 => 'Complete',
        5 => 'Retry required',
        6 => 'Error',
        7 => 'To be deleted',
    ];

    /** Statuses that mean something needs attention. */
    private const PROBLEM_STATUSES = [5, 6];

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function isAvailable(): bool
    {
        $connection = $this->resource->getConnection();

        return $connection->isTableExists($this->resource->getTableName(self::TABLE_STATUS))
            && $connection->isTableExists($this->resource->getTableName(self::TABLE_QUEUE));
    }

    /**
     * Message counts per queue and status.
     *
     * @return array<int, array{queue: string, counts: array<string, int>, total: int, problems: int}>
     */
    public function getQueues(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select     = $connection->select()
            ->from(
                ['qms' => $this->resource->getTableName(self::TABLE_STATUS)],
                ['status', 'total' => new \Zend_Db_Expr('COUNT(*)')]
            )
            ->join(
                ['q' => $this->resource->getTableName(self::TABLE_QUEUE)],
                'q.id = qms.queue_id',
                ['queue_name' => 'name']
            )
            ->group(['q.name', 'qms.status'])
            ->order('q.name ASC');

        $queues = [];
        foreach ($connection->fetchAll($select) as $row) {
            $name   = (string) $row['queue_name'];
            $status = (int) $row['status'];
            $total  = (int) $row['total'];

            if (!isset($queues[$name])) {
                $queues[$name] = ['queue' => $name, 'counts' => [], 'total' => 0, 'problems' => 0];
            }

            $queues[$name]['counts'][self::STATUS_LABELS[$status] ?? (string) $status] = $total;
            $queues[$name]['total'] += $total;

            if (in_array($status, self::PROBLEM_STATUSES, true)) {
                $queues[$name]['problems'] += $total;
            }
        }

        return array_values($queues);
    }
}
