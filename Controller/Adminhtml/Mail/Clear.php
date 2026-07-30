<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Mail;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Modracx\AdminDevTools\Model\ActivityLogger;
use Modracx\AdminDevTools\Model\MailCatcher;

class Clear extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Modracx_AdminDevTools::mail_clear';

    public function __construct(
        Context $context,
        private readonly MailCatcher $mailCatcher,
        private readonly ActivityLogger $activityLogger,
        private readonly JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            $removed = $this->mailCatcher->clear();

            $this->activityLogger->logAction(
                'delete',
                MailCatcher::TABLE,
                (string) __('Mail log cleared'),
                ['entries_removed' => ['', (string) $removed]]
            );

            return $result->setData([
                'success' => true,
                'message' => (string) __('Removed %1 message(s).', $removed),
            ]);
        } catch (\Exception $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
