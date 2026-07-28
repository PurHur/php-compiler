<?php

declare(strict_types=1);

namespace PHPCompiler\ext\opcache;

use PHPCompiler\Frame;
use PHPCompiler\VM\InternalStrictArg;

/**
 * opcache_invalidate() — returns false when Zend OPcache is absent/disabled
 * (php-src ext/opcache/ZendAccelerator.c; issue #23834).
 */
final class opcache_invalidate extends OpcacheFunction
{
    public function __construct()
    {
        parent::__construct('opcache_invalidate');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'opcache_invalidate', 1, 2);
        InternalStrictArg::resolveCoercibleStringArg($frame, 0, 'opcache_invalidate', 'filename');
        if (\count($frame->calledArgs) > 1) {
            $this->optionalBoolArg($frame, 1, false, 'force');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(false);
    }
}
