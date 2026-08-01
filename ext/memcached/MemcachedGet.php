<?php

declare(strict_types=1);

namespace PHPCompiler\ext\memcached;

use PHPCompiler\Frame;

/** Memcached::get(string $key) — VM (#6099). */
final class MemcachedGet extends MemcachedClassMethod
{
    public function __construct()
    {
        parent::__construct('get');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Memcached::get()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Memcached::get() expects at least 1 argument, 0 given');
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Memcached::get', 0, 'key');

        $state = VmMemcached::state($receiver);
        $socket = VmMemcached::ensureSocket($receiver, 'Memcached::get');
        if (null === $socket) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }

        $fullKey = $state->prefixKey.$key;
        $result = VmMemcachedNative::get($socket, $fullKey);
        $state->resultCode = $result['code'];
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result['value']) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result['value']);
    }
}
