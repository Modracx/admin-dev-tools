<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Mail;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\Element\BlockFactory;
use Modracx\AdminDevTools\Controller\Adminhtml\AbstractPanel;
use Modracx\AdminDevTools\Model\MailCatcher;

class View extends AbstractPanel
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
        $id = (int) $this->getRequest()->getParam('id');

        try {
            $mail = $this->mailCatcher->get($id);

            if ($mail === null) {
                return $this->error((string) __('That message is no longer in the log.'));
            }

            return $this->panel('Modracx_AdminDevTools::panel/mail_view.phtml', ['mail' => $mail]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
}
