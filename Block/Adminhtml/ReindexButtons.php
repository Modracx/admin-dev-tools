<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Modracx\AdminDevTools\Model\IndexerInsight;

class ReindexButtons extends Template
{
    protected $_template = 'Modracx_AdminDevTools::panel/index.phtml';

    public function __construct(
        Context $context,
        private readonly IndexerInsight $indexerInsight,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getIndexers(): array
    {
        return $this->indexerInsight->getIndexers();
    }

    public function canReindex(): bool
    {
        return $this->_authorization->isAllowed('Modracx_AdminDevTools::reindex');
    }

    public function canChangeMode(): bool
    {
        return $this->_authorization->isAllowed('Modracx_AdminDevTools::reindex_mode');
    }
}
