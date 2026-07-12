<?php

declare(strict_types=1);

namespace PHPCompiler\ext\opcache;

use PHPCompiler\Frame;

/** opcache_get_configuration() — Zend-shaped disabled probe (php-src ext/opcache; issue #4421). */
final class opcache_get_configuration extends OpcacheFunction
{
    public function __construct()
    {
        parent::__construct('opcache_get_configuration');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \ArgumentCountError(
                'opcache_get_configuration() expects exactly 0 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmOpcache::disabledConfiguration());
    }
}
