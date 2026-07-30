<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Wiring;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\Element\BlockFactory;
use Modracx\AdminDevTools\Controller\Adminhtml\AbstractPanel;
use Modracx\AdminDevTools\Model\WiringInspector;

class Index extends AbstractPanel
{
    public const ADMIN_RESOURCE = 'Modracx_AdminDevTools::wiring';

    public function __construct(
        Context $context,
        BlockFactory $blockFactory,
        JsonFactory $jsonFactory,
        private readonly WiringInspector $wiringInspector
    ) {
        parent::__construct($context, $blockFactory, $jsonFactory);
    }

    public function execute()
    {
        $type  = trim((string) $this->getRequest()->getParam('type', ''));
        $event = trim((string) $this->getRequest()->getParam('event', ''));

        $data = ['type' => $type, 'event' => $event, 'result' => null, 'observers' => null, 'error' => ''];

        try {
            if ($type !== '') {
                $data['result'] = $this->wiringInspector->inspectType($type);
            }
            if ($event !== '') {
                $data['observers'] = $this->wiringInspector->inspectEvent($event);
            }
        } catch (\Exception $e) {
            $data['error'] = $e->getMessage();
        }

        return $this->panel('Modracx_AdminDevTools::panel/wiring.phtml', $data);
    }
}
