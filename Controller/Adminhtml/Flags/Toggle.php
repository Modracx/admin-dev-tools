<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Flags;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Modracx\AdminDevTools\Model\ActivityLogger;
use Modracx\AdminDevTools\Model\DevFlags;

/**
 * Flip one dev flag. Separate permission from reading them: knowing hints are off is
 * harmless, turning them on for every shopper on a live storefront is not.
 */
class Toggle extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Modracx_AdminDevTools::flags_write';

    public function __construct(
        Context $context,
        private readonly DevFlags $devFlags,
        private readonly ActivityLogger $activityLogger,
        private readonly JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $key    = (string) $this->getRequest()->getParam('flag');
        $enable = (bool) $this->getRequest()->getParam('enable');

        if ($key === '') {
            return $result->setData(['success' => false, 'message' => (string) __('Missing flag.')]);
        }

        try {
            $message = $this->devFlags->toggle($key, $enable);

            $this->activityLogger->logAction('update', 'core_config_data', $key, [
                'enabled' => [$enable ? '0' : '1', $enable ? '1' : '0'],
            ]);

            return $result->setData(['success' => true, 'message' => $message]);
        } catch (\Exception $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
