<?php

declare(strict_types=1);

namespace PHPCompiler\ext\opcache;

use PHPCompiler\Frame;

/** opcache_get_status() — Zend-shaped disabled probe (php-src ext/opcache; issue #4421). */
final class opcache_get_status extends OpcacheFunction
{
    public function __construct()
    {
        parent::__construct('opcache_get_status');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'opcache_get_status() expects at most 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $includeScripts = $this->optionalBoolArg($frame, 0, true);
        $frame->returnVar->array(VmOpcache::disabledStatus($includeScripts));
    }
}
