<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Cache;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Cache\Manager;
use Magento\Framework\Controller\Result\JsonFactory;
use Modracx\AdminDevTools\Model\CacheTypeAcl;
use Modracx\AdminDevTools\Model\FlushVerifier;

class Flush extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Modracx_AdminDevTools::cache_flush';

    public function __construct(
        Context $context,
        private readonly Manager $cacheManager,
        private readonly CacheTypeAcl $cacheTypeAcl,
        private readonly JsonFactory $jsonFactory,
        private readonly FlushVerifier $verifier
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $type   = (string) $this->getRequest()->getParam('type');

        // Validated against the live cache type registry, so any module's cache type works.
        if (!in_array($type, $this->cacheManager->getAvailableTypes(), true)) {
            return $result->setData(['success' => false, 'message' => (string) __('Invalid cache type.')]);
        }

        if (!$this->_authorization->isAllowed($this->cacheTypeAcl->getResource($type))) {
            return $result->setData(['success' => false, 'message' => (string) __('Access denied.')]);
        }

        try {
            // Seeded before the clean and read back after it, so the answer describes what
            // happened rather than what was attempted.
            $probe = $this->verifier->probeType($type);
            $this->cacheManager->clean([$type]);
            $verified = $this->verifier->verifyType($type, $probe);

            return $result->setData([
                'success' => $verified !== false,
                'message' => (string) __('Cache flushed successfully.') . $this->verifier->note($verified),
            ]);
        } catch (\Exception $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
