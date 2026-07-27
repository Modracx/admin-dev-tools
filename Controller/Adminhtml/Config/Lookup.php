<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Config;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\Element\BlockFactory;
use Modracx\AdminDevTools\Controller\Adminhtml\AbstractPanel;
use Modracx\AdminDevTools\Model\ConfigInspector;

class Lookup extends AbstractPanel
{
    public const ADMIN_RESOURCE = 'Modracx_AdminDevTools::config_inspect';

    public function __construct(
        Context $context,
        BlockFactory $blockFactory,
        JsonFactory $jsonFactory,
        private readonly ConfigInspector $configInspector
    ) {
        parent::__construct($context, $blockFactory, $jsonFactory);
    }

    public function execute()
    {
        $path = (string) $this->getRequest()->getParam('path', '');

        if (trim($path) === '') {
            return $this->panel('Modracx_AdminDevTools::panel/config_lookup.phtml', [
                'path'    => '',
                'results' => [],
            ]);
        }

        try {
            $results = $this->configInspector->lookup($path);
        } catch (\Exception $e) {
            return $this->panel('Modracx_AdminDevTools::panel/config_lookup.phtml', [
                'path'    => $path,
                'results' => [],
                'error'   => $e->getMessage(),
            ]);
        }

        return $this->panel('Modracx_AdminDevTools::panel/config_lookup.phtml', [
            'path'    => $path,
            'results' => $results,
        ]);
    }
}
