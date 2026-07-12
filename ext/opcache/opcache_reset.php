<?php

declare(strict_types=1);

namespace PHPCompiler\ext\opcache;

use PHPCompiler\Frame;

/** opcache_reset() — no-op when Zend OPcache is absent (php-src ext/opcache; issue #4421). */
final class opcache_reset extends OpcacheFunction
{
    public function __construct()
    {
        parent::__construct('opcache_reset');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \ArgumentCountError(
                'opcache_reset() expects exactly 0 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(false);
    }
}
