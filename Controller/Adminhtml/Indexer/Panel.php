<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Indexer;

use Modracx\AdminDevTools\Block\Adminhtml\ReindexButtons;
use Modracx\AdminDevTools\Controller\Adminhtml\AbstractPanel;

class Panel extends AbstractPanel
{
    public const ADMIN_RESOURCE = 'Modracx_AdminDevTools::reindex';

    public function execute()
    {
        return $this->panel('Modracx_AdminDevTools::panel/index.phtml', [], ReindexButtons::class);
    }
}
