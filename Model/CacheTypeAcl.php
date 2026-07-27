<?php
declare(strict_types=1);

namespace Modracx\AdminDevTools\Model;

/**
 * Maps a cache type code to the ACL resource that guards flushing it.
 *
 * The three core types keep their own dedicated resources so existing role
 * configurations stay meaningful. Every other type — including ones declared by
 * third-party modules, which cannot be known ahead of time — falls back to the
 * catch-all resource.
 */
class CacheTypeAcl
{
    public const RESOURCE_OTHER = 'Modracx_AdminDevTools::flush_other';

    private const MAP = [
        'config'     => 'Modracx_AdminDevTools::flush_config',
        'block_html' => 'Modracx_AdminDevTools::flush_block',
        'full_page'  => 'Modracx_AdminDevTools::flush_fpc',
    ];

    /**
     * ACL resource required to flush the given cache type.
     */
    public function getResource(string $cacheType): string
    {
        return self::MAP[$cacheType] ?? self::RESOURCE_OTHER;
    }
}
