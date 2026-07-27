<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Cron;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Modracx\AdminDevTools\Model\CronHealth;

/**
 * Just the health verdict, for the toolbar badge.
 *
 * Kept separate from Status so the badge — which every admin page requests once — costs
 * two small aggregate queries rather than the whole panel's worth of work.
 */
class Badge extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Modracx_AdminDevTools::cron_health';

    public function __construct(
        Context $context,
        private readonly CronHealth $cronHealth,
        private readonly JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            return $result->setData(['success' => true, 'badge' => $this->cronHealth->getBadge()]);
        } catch (\Exception $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
