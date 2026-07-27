<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Log;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\Element\BlockFactory;
use Modracx\AdminDevTools\Controller\Adminhtml\AbstractPanel;
use Modracx\AdminDevTools\Model\LogTail;

class View extends AbstractPanel
{
    public const ADMIN_RESOURCE = 'Modracx_AdminDevTools::logs';

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
        // The Logs tab opens with no parameters, so fall back to the first log rather
        // than treating "nothing chosen yet" as an invalid choice.
        $file  = (string) $this->getRequest()->getParam('file', '');
        $file  = $file !== '' ? $file : $this->logTail->getDefaultId();
        $lines = (int) $this->getRequest()->getParam('lines', LogTail::DEFAULT_LINES);

        if (!$this->logTail->has($file)) {
            return $this->error((string) __('Unknown log file.'));
        }

        try {
            $tail = $this->logTail->tail($file, $lines);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }

        return $this->panel('Modracx_AdminDevTools::panel/log.phtml', [
            'file'      => $file,
            'files'     => $this->logTail->getFiles(),
            'tail'      => $tail,
            'lines'     => $lines,
            'choices'   => LogTail::LINE_CHOICES,
            'can_clear' => $this->_authorization->isAllowed('Modracx_AdminDevTools::logs_clear'),
        ]);
    }
}
