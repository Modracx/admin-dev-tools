<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Cron;

use Modracx\AdminDevTools\Model\ActivityFeed;

/**
 * Keeps the activity log bounded. Without this it grows for the life of the store.
 */
class PruneActivityLog
{
    public function __construct(private readonly ActivityFeed $activityFeed)
    {
    }

    public function execute(): void
    {
        $this->activityFeed->prune();
    }
}
