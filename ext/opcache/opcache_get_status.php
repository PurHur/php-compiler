<?php

declare(strict_types=1);

namespace PHPCompiler\ext\opcache;

use PHPCompiler\Frame;

/** opcache_get_status() — returns false when OPcache is not active (php-src ZendAccelerator.c; #21755). */
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
        if ($argc > 0) {
            $this->optionalBoolArg($frame, 0, true);
        }
        $frame->returnVar->bool(false);
    }
}
