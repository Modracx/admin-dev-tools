<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Model\AbstractModel;
use Psr\Log\LoggerInterface;

/**
 * Records what changed on backend model saves and deletes.
 *
 * Scope is deliberately narrow, because the alternative is unusable:
 *
 *  - Only the areas whose events.xml binds the observers — adminhtml, REST, SOAP and
 *    GraphQL. Storefront saves (quotes, sessions, visitor rows) never reach this class
 *    at all, so there is no cost on the frontend.
 *  - High-churn entities are skipped even in those areas; an indexer run from the admin
 *    would otherwise bury the human changes you actually came to read.
 *  - Values are masked by the same rules as the config inspector.
 *
 * What this cannot see: writes that bypass the model layer — direct $connection->update()
 * calls, mass actions implemented as raw SQL, and most indexer internals. That is a real
 * limit of hooking models rather than the database, and it is stated in the panel rather
 * than left for someone to discover during an incident.
 */
class ActivityLogger
{
    public const TABLE = 'modracx_activity_log';

    /**
     * Entities that change constantly and carry no audit value.
     * Matched against the model's main table.
     */
    private const SKIP_TABLES = [
        'quote', 'quote_item', 'quote_address', 'quote_address_item', 'quote_payment',
        'cron_schedule', 'report_event', 'report_viewed_product_index', 'report_compared_product_index',
        'customer_visitor', 'customer_log',
        'indexer_state', 'mview_state', 'flag',
        'search_query', 'session', 'admin_user_session',
        'magento_logging_event', 'adminnotification_inbox',
        self::TABLE,
    ];

    /** Fields that are noise in a diff. */
    private const SKIP_FIELDS = ['updated_at', 'created_at', 'modracx_changes'];

    private const MAX_CHANGES_BYTES = 65000;

    /** Pending diffs captured before save, keyed by object id. */
    private array $pending = [];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ActivityContext $context,
        private readonly ValueMasker $masker,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Capture the before-state. Called on model_save_before, where getOrigData() is
     * still the values as loaded — after the save it is not reliably the old row.
     */
    public function captureBeforeSave(AbstractModel $object): void
    {
        if (!$this->shouldLog($object)) {
            return;
        }

        $isNew   = !$object->getOrigData($object->getIdFieldName()) && $object->isObjectNew();
        $changes = $this->diff($object);

        if (!$isNew && empty($changes)) {
            return;     // a save that changed nothing is not an event
        }

        $this->pending[spl_object_id($object)] = [
            'action'  => $isNew ? 'create' : 'update',
            'changes' => $changes,
        ];
    }

    /**
     * Write the row now that a new record has its id.
     */
    public function commitAfterSave(AbstractModel $object): void
    {
        $key = spl_object_id($object);
        if (!isset($this->pending[$key])) {
            return;
        }

        $pending = $this->pending[$key];
        unset($this->pending[$key]);

        $this->write($object, $pending['action'], $pending['changes']);
    }

    public function logDelete(AbstractModel $object): void
    {
        if (!$this->shouldLog($object)) {
            return;
        }

        $this->write($object, 'delete', []);
    }

    /**
     * Direct entry point for things that are not model saves (e.g. clearing this log).
     */
    public function logAction(string $action, string $entityType, ?string $label = null, array $changes = []): void
    {
        $this->insert([
            'action'        => $action,
            'entity_type'   => $entityType,
            'entity_table'  => null,
            'entity_id'     => null,
            'entity_label'  => $label,
            'changes'       => $changes ? $this->encode($changes) : null,
        ]);
    }

    private function shouldLog(AbstractModel $object): bool
    {
        $table = $this->mainTable($object);

        return $table === null || !in_array($table, self::SKIP_TABLES, true);
    }

    /**
     * @return array<string, array{0: string, 1: string}> field => [old, new]
     */
    private function diff(AbstractModel $object): array
    {
        $changes = [];

        // core_config_data stores every setting in a column literally called "value",
        // so the field name alone says nothing about sensitivity — the path does. The
        // label is folded into the check so payment/gateway/api_key is recognised.
        $context = (string) $this->label($object);

        foreach ($object->getData() as $field => $new) {
            if (in_array($field, self::SKIP_FIELDS, true)) {
                continue;
            }

            $old = $object->getOrigData($field);

            // Loose comparison on purpose: "1" and 1 out of the DB are not a change.
            if ($old == $new) {
                continue;
            }

            $sensitive = $this->masker->isSensitive(
                trim($field . ' ' . $context),
                is_string($new) ? $new : null
            );

            $changes[(string) $field] = [
                $this->masker->present($old, $sensitive, 120),
                $this->masker->present($new, $sensitive, 120),
            ];
        }

        return $changes;
    }

    private function write(AbstractModel $object, string $action, array $changes): void
    {
        $this->insert([
            'action'       => $action,
            'entity_type'  => get_class($object),
            'entity_table' => $this->mainTable($object),
            'entity_id'    => $object->getId() !== null ? (string) $object->getId() : null,
            'entity_label' => $this->label($object),
            'changes'      => $changes ? $this->encode($changes) : null,
        ]);
    }

    private function insert(array $row): void
    {
        try {
            $connection = $this->resource->getConnection();
            $table      = $this->resource->getTableName(self::TABLE);

            // The table only exists after setup:upgrade; never break a save over logging.
            if (!$connection->isTableExists($table)) {
                return;
            }

            $connection->insert($table, array_merge($this->context->get(), $row));
        } catch (\Exception $e) {
            // Auditing must never be the reason a legitimate save fails.
            $this->logger->warning('Modracx activity log write failed: ' . $e->getMessage());
        }
    }

    private function encode(array $changes): string
    {
        $json = json_encode($changes);

        if ($json === false) {
            return '{}';
        }

        return strlen($json) > self::MAX_CHANGES_BYTES
            ? (string) json_encode(['_truncated' => ['', 'too many changes to store']])
            : $json;
    }

    private function mainTable(AbstractModel $object): ?string
    {
        try {
            $resource = $object->getResource();

            return method_exists($resource, 'getMainTable') ? (string) $resource->getMainTable() : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Something a human can recognise the record by.
     *
     * Read straight from the data array rather than through getters: most Magento
     * models expose getPath()/getSku() via __call, where method_exists() reports false
     * and a getter-based lookup silently finds nothing.
     */
    private function label(AbstractModel $object): ?string
    {
        foreach (['path', 'sku', 'name', 'title', 'code', 'email', 'increment_id', 'identifier'] as $key) {
            $value = $object->getData($key);

            if (is_scalar($value) && (string) $value !== '') {
                return substr((string) $value, 0, 255);
            }
        }

        return null;
    }
}
