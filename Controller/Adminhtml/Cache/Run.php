<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Cache;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Modracx\AdminDevTools\Model\CacheAction;
use Modracx\AdminDevTools\Model\FlushVerifier;

/**
 * Runs one of the "Additional Cache Management" actions over AJAX.
 */
class Run extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Modracx_AdminDevTools::cache_flush';

    public function __construct(
        Context $context,
        private readonly CacheAction $cacheAction,
        private readonly JsonFactory $jsonFactory,
        private readonly FlushVerifier $verifier
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $action = (string) $this->getRequest()->getParam('action');

        $resource = $this->cacheAction->getResource($action);
        if ($resource === null) {
            return $result->setData(['success' => false, 'message' => (string) __('Unknown cache action.')]);
        }

        if (!$this->_authorization->isAllowed($resource)) {
            return $result->setData(['success' => false, 'message' => (string) __('Access denied.')]);
        }

        try {
            // Only the two storage-wide actions are supposed to leave every frontend empty.
            // The file-cleaning ones (images, js/css, static) touch no cache frontend at all,
            // so probing them would report a false failure.
            $wipesEverything = in_array($action, [CacheAction::SYSTEM, CacheAction::STORAGE], true);
            $probes = $wipesEverything ? $this->verifier->probeAllFrontends() : [];

            $message = $this->cacheAction->execute($action);
            $survivors = $wipesEverything ? $this->verifier->survivors($probes) : [];

            return $result->setData([
                'success' => $survivors === [],
                'message' => $message . $this->verifier->note(null, $survivors),
            ]);
        } catch (\Exception $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
