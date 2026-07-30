<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Cron;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Modracx\AdminDevTools\Model\ActivityLogger;
use Modracx\AdminDevTools\Model\CronRunner;

/**
 * Run one cron job on demand.
 *
 * Behind its own ACL, separate from reading cron health: looking at the schedule is
 * harmless, executing arbitrary declared jobs on a live store is not.
 */
class Run extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Modracx_AdminDevTools::cron_run';

    public function __construct(
        Context $context,
        private readonly CronRunner $cronRunner,
        private readonly ActivityLogger $activityLogger,
        private readonly JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result  = $this->jsonFactory->create();
        $jobCode = (string) $this->getRequest()->getParam('job_code');

        if ($jobCode === '') {
            return $result->setData(['success' => false, 'message' => (string) __('Missing job_code.')]);
        }

        try {
            $run = $this->cronRunner->run($jobCode);

            $this->activityLogger->logAction('cron_run', 'cron_schedule', $jobCode, [
                'group'   => ['', $run['group']],
                'seconds' => ['', (string) $run['seconds']],
            ]);

            return $result->setData([
                'success' => true,
                'message' => (string) __('%1 finished in %2s.', $jobCode, $run['seconds']),
            ]);
        } catch (\Exception $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
