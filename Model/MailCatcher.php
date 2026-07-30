<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Mail\EmailMessageInterface;
use Magento\Framework\Mail\MessageInterface;
use Psr\Log\LoggerInterface;

/**
 * Every mail the application tried to send, kept where it can be read.
 *
 * Magento has no record of outgoing mail: a transactional email either arrives or it does
 * not, and when it does not there is nothing to look at. This records the rendered message
 * as it was handed to the transport — after templating, after variables, which is the
 * version worth reading.
 */
class MailCatcher
{
    public const TABLE = 'modracx_mail_log';

    /** Suppression is a development convenience; it must never silently apply in production. */
    private const SUPPRESS_PATH = 'dev/modracx/mail_suppress';

    private const RETENTION_DAYS = 14;

    private const LIMIT = 50;

    private const MAX_BODY = 500000;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly State $appState,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Should delivery be skipped for this message?
     *
     * Production is excluded unconditionally and not as a configurable choice: a store
     * that quietly stops sending order confirmations is a far worse outcome than any
     * debugging convenience this setting buys.
     */
    public function shouldSuppress(): bool
    {
        try {
            if ($this->appState->getMode() === State::MODE_PRODUCTION) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }

        return (bool) $this->scopeConfig->getValue(self::SUPPRESS_PATH);
    }

    /**
     * Record one message. Never throws: a logging failure must not stop a mail.
     */
    public function record(MessageInterface $message, bool $delivered, ?string $error = null): void
    {
        try {
            $connection = $this->resource->getConnection();
            $table      = $this->resource->getTableName(self::TABLE);

            if (!$connection->isTableExists($table)) {
                return;
            }

            $connection->insert($table, [
                'subject'      => $this->truncate((string) $message->getSubject(), 255),
                'mail_to'      => $this->addresses($message, 'getTo'),
                'mail_from'    => $this->addresses($message, 'getFrom'),
                'mail_cc'      => $this->addresses($message, 'getCc'),
                'mail_bcc'     => $this->addresses($message, 'getBcc'),
                'content_type' => $this->contentType($message),
                'body'         => $this->truncate($this->body($message), self::MAX_BODY),
                'delivered'    => $delivered ? 1 : 0,
                'error'        => $error !== null ? $this->truncate($error, 2000) : null,
            ]);
        } catch (\Throwable $e) {
            // \Throwable, not \Exception: reading a message can raise an \Error when a
            // transport hands over a body shape the framework's own accessors cannot
            // re-wrap. Recording a mail must never be the reason one fails to send.
            $this->logger->warning('Modracx mail log write failed: ' . $e->getMessage());
        }
    }

    /**
     * @return array<int, array{mail_id: int, sent_at: string, subject: string, mail_to: string, delivered: bool, error: ?string}>
     */
    public function getRecent(int $limit = self::LIMIT): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select     = $connection->select()
            ->from(
                $this->resource->getTableName(self::TABLE),
                ['mail_id', 'sent_at', 'subject', 'mail_to', 'delivered', 'error']
            )
            ->order('mail_id DESC')
            ->limit(max(1, min($limit, self::LIMIT)));

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[] = [
                'mail_id'   => (int) $row['mail_id'],
                'sent_at'   => (string) $row['sent_at'],
                'subject'   => (string) ($row['subject'] ?? ''),
                'mail_to'   => (string) ($row['mail_to'] ?? ''),
                'delivered' => (bool) $row['delivered'],
                'error'     => $row['error'] !== null ? (string) $row['error'] : null,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $mailId): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE))
                ->where('mail_id = ?', $mailId)
        );

        return $row ?: null;
    }

    public function clear(): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        return (int) $this->resource->getConnection()->delete($this->resource->getTableName(self::TABLE));
    }

    /**
     * Called from cron so the table cannot grow for the life of the store.
     */
    public function prune(int $days = self::RETENTION_DAYS): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        return (int) $this->resource->getConnection()->delete(
            $this->resource->getTableName(self::TABLE),
            ['sent_at < ?' => new \Zend_Db_Expr(sprintf('DATE_SUB(NOW(), INTERVAL %d DAY)', max(1, $days)))]
        );
    }

    public function isAvailable(): bool
    {
        return $this->resource->getConnection()->isTableExists($this->resource->getTableName(self::TABLE));
    }

    public function getRetentionDays(): int
    {
        return self::RETENTION_DAYS;
    }

    public function getSuppressPath(): string
    {
        return self::SUPPRESS_PATH;
    }

    /**
     * Addresses arrive as objects on EmailMessageInterface and are absent entirely on the
     * older MessageInterface, which some third-party transports still hand over.
     */
    private function addresses(MessageInterface $message, string $method): ?string
    {
        if (!$message instanceof EmailMessageInterface || !method_exists($message, $method)) {
            return null;
        }

        try {
            $addresses = $message->$method();
        } catch (\Throwable $e) {
            return null;
        }

        if (empty($addresses)) {
            return null;
        }

        $parts = [];
        foreach ($addresses as $address) {
            if (is_object($address) && method_exists($address, 'getEmail')) {
                $name    = method_exists($address, 'getName') ? (string) $address->getName() : '';
                $parts[] = $name !== '' ? sprintf('%s <%s>', $name, $address->getEmail()) : (string) $address->getEmail();
                continue;
            }
            $parts[] = (string) $address;
        }

        return $this->truncate(implode(', ', $parts), 2000);
    }

    private function body(MessageInterface $message): string
    {
        try {
            if ($message instanceof EmailMessageInterface) {
                return $message->getBodyText();
            }

            $body = $message->getBody();

            return is_object($body) && method_exists($body, 'getParts')
                ? $this->partsToString($body)
                : (string) $body;
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function partsToString(object $body): string
    {
        $out = [];
        foreach ($body->getParts() as $part) {
            $out[] = method_exists($part, 'getRawContent') ? (string) $part->getRawContent() : '';
        }

        return implode("\n", $out);
    }

    /**
     * Read the content type off the headers rather than off the body.
     *
     * getMessageBody() rebuilds the MIME structure through the framework's own factories
     * and raises a fatal on message shapes those factories cannot re-wrap — which is a
     * poor trade for one string. The header is already rendered and always readable.
     */
    private function contentType(MessageInterface $message): ?string
    {
        try {
            if (!$message instanceof EmailMessageInterface) {
                return null;
            }

            foreach ($message->getHeaders() as $header) {
                if (is_string($header) && stripos($header, 'content-type:') === 0) {
                    return $this->truncate(trim(substr($header, strlen('content-type:'))), 100);
                }
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    private function truncate(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) . '…' : $value;
    }
}
