<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Cache\TypeListInterface;
use Modracx\AdminDevTools\Model\CacheAction;
use Modracx\AdminDevTools\Model\CacheTypeAcl;

class CacheButtons extends Template
{
    protected $_template = 'Modracx_AdminDevTools::panel/cache.phtml';

    /** @var array<int, array<string, mixed>>|null */
    private ?array $cacheTypes = null;

    public function __construct(
        Context $context,
        private readonly TypeListInterface $cacheTypeList,
        private readonly CacheTypeAcl $cacheTypeAcl,
        private readonly CacheAction $cacheAction,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Every declared cache type the current user is allowed to flush.
     *
     * Read from Magento\Framework\App\Cache\TypeListInterface, i.e. the merged cache.xml
     * of all enabled modules — a third-party cache type appears here automatically.
     *
     * @return array<int, array{id: string, label: string, enabled: bool, invalidated: bool}>
     */
    public function getCacheTypes(): array
    {
        if ($this->cacheTypes !== null) {
            return $this->cacheTypes;
        }

        $invalidated = $this->cacheTypeList->getInvalidated();
        $types       = [];

        foreach ($this->cacheTypeList->getTypes() as $type) {
            $id = (string) $type->getId();
            if (!$this->canFlushType($id)) {
                continue;
            }
            $types[] = [
                'id'          => $id,
                'label'       => (string) $type->getCacheType(),
                'enabled'     => (bool) $type->getStatus(),
                'invalidated' => isset($invalidated[$id]),
            ];
        }

        $this->cacheTypes = $types;

        return $types;
    }

    /**
     * Additional cache management actions the current user is allowed to run.
     *
     * @return array<int, array{id: string, label: string, description: string}>
     */
    public function getCacheActions(): array
    {
        $actions = [];

        foreach ($this->cacheAction->getActions() as $id => $action) {
            if (!$this->_authorization->isAllowed($action['resource'])) {
                continue;
            }
            $actions[] = [
                'id'          => $id,
                'label'       => $action['label'],
                'description' => $action['description'],
            ];
        }

        return $actions;
    }

    public function canFlushType(string $cacheType): bool
    {
        return $this->_authorization->isAllowed($this->cacheTypeAcl->getResource($cacheType));
    }
}
