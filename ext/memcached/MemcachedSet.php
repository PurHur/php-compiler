<?php

declare(strict_types=1);

namespace PHPCompiler\ext\memcached;

use PHPCompiler\Frame;

/** Memcached::set(string $key, mixed $value, int $expiration = 0) — VM (#6099). */
final class MemcachedSet extends MemcachedClassMethod
{
    public function __construct()
    {
        parent::__construct('set');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Memcached::set()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'Memcached::set() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Memcached::set', 0, 'key');
        $value = VmMemcached::coerceValueToString($frame->calledArgs[2], 'Memcached::set', 1, 'value');
        $expiration = \count($frame->calledArgs) >= 4
            ? $this->intArg($frame->calledArgs[3], 'Memcached::set', 2, 'expiration', 0)
            : 0;

        $state = VmMemcached::state($receiver);
        $socket = VmMemcached::ensureSocket($receiver, 'Memcached::set');
        if (null === $socket) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }

        $fullKey = $state->prefixKey.$key;
        $result = VmMemcachedNative::set($socket, $fullKey, $value, $expiration);
        $state->resultCode = $result['code'];
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($result['ok']);
        }
    }
}
