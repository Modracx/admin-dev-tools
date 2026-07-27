<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Controller\Adminhtml\Cache;

use Modracx\AdminDevTools\Block\Adminhtml\CacheButtons;
use Modracx\AdminDevTools\Controller\Adminhtml\AbstractPanel;

class Panel extends AbstractPanel
{
    public const ADMIN_RESOURCE = 'Modracx_AdminDevTools::cache_flush';

    public function execute()
    {
        return $this->panel('Modracx_AdminDevTools::panel/cache.phtml', [], CacheButtons::class);
    }
}
