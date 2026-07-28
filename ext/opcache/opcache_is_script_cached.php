<?php

declare(strict_types=1);

namespace PHPCompiler\ext\opcache;

use PHPCompiler\Frame;
use PHPCompiler\VM\InternalStrictArg;

/**
 * opcache_is_script_cached() — returns false when Zend OPcache is absent/disabled
 * (php-src ext/opcache/ZendAccelerator.c; issue #23834).
 */
final class opcache_is_script_cached extends OpcacheFunction
{
    public function __construct()
    {
        parent::__construct('opcache_is_script_cached');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'opcache_is_script_cached', 1);
        InternalStrictArg::resolveCoercibleStringArg($frame, 0, 'opcache_is_script_cached', 'filename');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(false);
    }
}
