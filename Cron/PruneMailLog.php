<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Cron;

use Modracx\AdminDevTools\Model\MailCatcher;

/**
 * Keeps the captured mail bounded. Bodies are the largest thing this module stores, so
 * without this the table outgrows everything else it writes.
 */
class PruneMailLog
{
    public function __construct(private readonly MailCatcher $mailCatcher)
    {
    }

    public function execute(): void
    {
        $this->mailCatcher->prune();
    }
}
