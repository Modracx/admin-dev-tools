<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Rewrite;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\Element\BlockFactory;
use Modracx\AdminDevTools\Controller\Adminhtml\AbstractPanel;
use Modracx\AdminDevTools\Model\RewriteLookup;

class Index extends AbstractPanel
{
    public const ADMIN_RESOURCE = 'Modracx_AdminDevTools::rewrites';

    public function __construct(
        Context $context,
        BlockFactory $blockFactory,
        JsonFactory $jsonFactory,
        private readonly RewriteLookup $rewriteLookup
    ) {
        parent::__construct($context, $blockFactory, $jsonFactory);
    }

    public function execute()
    {
        $path = trim((string) $this->getRequest()->getParam('path', ''));

        if ($path === '') {
            return $this->panel('Modracx_AdminDevTools::panel/rewrites.phtml', ['path' => '', 'result' => null]);
        }

        try {
            return $this->panel('Modracx_AdminDevTools::panel/rewrites.phtml', [
                'path'   => $path,
                'result' => $this->rewriteLookup->lookup($path),
            ]);
        } catch (\Exception $e) {
            return $this->panel('Modracx_AdminDevTools::panel/rewrites.phtml', [
                'path'   => $path,
                'result' => null,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
