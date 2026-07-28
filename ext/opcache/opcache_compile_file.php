<?php

declare(strict_types=1);

namespace PHPCompiler\ext\opcache;

use PHPCompiler\Frame;
use PHPCompiler\VM\InternalStrictArg;

/**
 * opcache_compile_file() — returns false when Zend OPcache is absent/disabled
 * (php-src ext/opcache/ZendAccelerator.c; issue #23834).
 */
final class opcache_compile_file extends OpcacheFunction
{
    public function __construct()
    {
        parent::__construct('opcache_compile_file');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'opcache_compile_file', 1);
        InternalStrictArg::resolveCoercibleStringArg($frame, 0, 'opcache_compile_file', 'filename');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(false);
    }
}
