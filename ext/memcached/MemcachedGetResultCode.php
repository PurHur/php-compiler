<?php

declare(strict_types=1);

namespace PHPCompiler\ext\memcached;

use PHPCompiler\Frame;

/** Memcached::getResultCode(): int — VM (#6099). */
final class MemcachedGetResultCode extends MemcachedClassMethod
{
    public function __construct()
    {
        parent::__construct('getResultCode');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Memcached::getResultCode()');
        $state = VmMemcached::state($receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($state->resultCode);
        }
    }
}
