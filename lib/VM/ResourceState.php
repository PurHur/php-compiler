<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Internal handle payload for PHP 8.4 {@see Resource} object zvals (#7073, #7071).
 *
 * php-src ref: Zend/zend_types.h — resource → object migration
 */
final class ResourceState
{
    public const KIND_STREAM = 'stream';

    public const KIND_DIR = 'dir';

    public const KIND_BRIGADE = 'stream-filter';

    public const KIND_BUCKET = 'stream-filter';

    public const KIND_STREAM_FILTER = 'stream filter';

    public const KIND_PROCESS = 'process';

    /** pecl-text-wddx le_wddx — "WDDX packet ID" (#27858). */
    public const KIND_WDDX_PACKET = 'WDDX packet ID';

    public function __construct(
        public int $handle,
        public string $kind,
    ) {
    }
}
