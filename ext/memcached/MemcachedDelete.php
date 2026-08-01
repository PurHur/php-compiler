<?php

declare(strict_types=1);

namespace PHPCompiler\ext\memcached;

use PHPCompiler\Frame;

/** Memcached::delete(string $key, int $time = 0) — VM (#6099). */
final class MemcachedDelete extends MemcachedClassMethod
{
    public function __construct()
    {
        parent::__construct('delete');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Memcached::delete()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Memcached::delete() expects at least 1 argument, 0 given');
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Memcached::delete', 0, 'key');

        $state = VmMemcached::state($receiver);
        $socket = VmMemcached::ensureSocket($receiver, 'Memcached::delete');
        if (null === $socket) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }

        $fullKey = $state->prefixKey.$key;
        $result = VmMemcachedNative::delete($socket, $fullKey);
        $state->resultCode = $result['code'];
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($result['ok']);
        }
    }
}
