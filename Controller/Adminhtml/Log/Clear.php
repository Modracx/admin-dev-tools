<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Log;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\Element\BlockFactory;
use Modracx\AdminDevTools\Controller\Adminhtml\AbstractPanel;
use Modracx\AdminDevTools\Model\LogTail;

class Clear extends AbstractPanel
{
    public const ADMIN_RESOURCE = 'Modracx_AdminDevTools::logs_clear';

    public function __construct(
        Context $context,
        BlockFactory $blockFactory,
        JsonFactory $jsonFactory,
        private readonly LogTail $logTail
    ) {
        parent::__construct($context, $blockFactory, $jsonFactory);
    }

    public function execute()
    {
        $file = (string) $this->getRequest()->getParam('file');

        if (!$this->logTail->has($file)) {
            return $this->error((string) __('Unknown log file.'));
        }

        try {
            $this->logTail->clear($file);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }

        return $this->success((string) __('%1 cleared.', $this->logTail->getFiles()[$file]));
    }
}
