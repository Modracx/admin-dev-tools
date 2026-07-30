<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Cron;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\Element\BlockFactory;
use Modracx\AdminDevTools\Controller\Adminhtml\AbstractPanel;
use Modracx\AdminDevTools\Model\CronHealth;
use Modracx\AdminDevTools\Model\QueueHealth;

class Status extends AbstractPanel
{
    public const ADMIN_RESOURCE = 'Modracx_AdminDevTools::cron_health';

    public function __construct(
        Context $context,
        BlockFactory $blockFactory,
        JsonFactory $jsonFactory,
        private readonly CronHealth $cronHealth,
        private readonly QueueHealth $queueHealth
    ) {
        parent::__construct($context, $blockFactory, $jsonFactory);
    }

    public function execute()
    {
        $runFilter = (string) ($this->getRequest()->getParam('run_status') ?: 'all');

        try {
            return $this->panel('Modracx_AdminDevTools::panel/cron.phtml', [
                'badge'            => $this->cronHealth->getBadge(),
                'counts'           => $this->cronHealth->getStatusCounts(),
                'groups'           => $this->cronHealth->getGroups(),
                'jobs'             => $this->cronHealth->getJobs(),
                'runs'             => $this->cronHealth->getRecentRuns($runFilter),
                'run_status'       => $runFilter,
                'recent_hours'     => $this->cronHealth->getRecentHours(),
                'success_days'     => $this->cronHealth->getSuccessDays(),
                'can_run'          => $this->_authorization->isAllowed('Modracx_AdminDevTools::cron_run'),
                'queues'           => $this->queueHealth->getQueues(),
                'queues_available' => $this->queueHealth->isAvailable(),
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
}
