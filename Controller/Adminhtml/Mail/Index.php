<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Mail;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\Element\BlockFactory;
use Modracx\AdminDevTools\Controller\Adminhtml\AbstractPanel;
use Modracx\AdminDevTools\Model\MailCatcher;

class Index extends AbstractPanel
{
    public const ADMIN_RESOURCE = 'Modracx_AdminDevTools::mail';

    public function __construct(
        Context $context,
        BlockFactory $blockFactory,
        JsonFactory $jsonFactory,
        private readonly MailCatcher $mailCatcher
    ) {
        parent::__construct($context, $blockFactory, $jsonFactory);
    }

    public function execute()
    {
        try {
            return $this->panel('Modracx_AdminDevTools::panel/mail.phtml', [
                'mails'      => $this->mailCatcher->getRecent(),
                'available'  => $this->mailCatcher->isAvailable(),
                'suppressed' => $this->mailCatcher->shouldSuppress(),
                'retention'  => $this->mailCatcher->getRetentionDays(),
                'can_clear'  => $this->_authorization->isAllowed('Modracx_AdminDevTools::mail_clear'),
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
}
