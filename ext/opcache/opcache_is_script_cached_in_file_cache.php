<?php

declare(strict_types=1);

namespace PHPCompiler\ext\opcache;

use PHPCompiler\Frame;
use PHPCompiler\VM\InternalStrictArg;

/**
 * opcache_is_script_cached_in_file_cache() — PHP 8.5+ file-cache probe.
 *
 * Returns false when Zend OPcache / file cache is absent (same honesty as
 * {@see opcache_is_script_cached}; php-src ext/opcache/opcache.stub.php; #27675).
 */
final class opcache_is_script_cached_in_file_cache extends OpcacheFunction
{
    public function __construct()
    {
        parent::__construct('opcache_is_script_cached_in_file_cache');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'opcache_is_script_cached_in_file_cache', 1);
        InternalStrictArg::resolveCoercibleStringArg(
            $frame,
            0,
            'opcache_is_script_cached_in_file_cache',
            'filename'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(false);
    }
}
