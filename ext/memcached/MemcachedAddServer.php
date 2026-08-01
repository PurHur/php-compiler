<?php

declare(strict_types=1);

namespace PHPCompiler\ext\memcached;

use PHPCompiler\Frame;

/** Memcached::addServer(string $host, int $port = 11211, int $weight = 0) — VM (#6099). */
final class MemcachedAddServer extends MemcachedClassMethod
{
    public function __construct()
    {
        parent::__construct('addServer');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Memcached::addServer()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Memcached::addServer() expects at least 1 argument, 0 given');
        }
        $host = $this->stringArg($frame->calledArgs[1], 'Memcached::addServer', 0, 'host');
        $port = \count($frame->calledArgs) >= 3
            ? $this->intArg($frame->calledArgs[2], 'Memcached::addServer', 1, 'port', 11211)
            : 11211;
        $weight = \count($frame->calledArgs) >= 4
            ? $this->intArg($frame->calledArgs[3], 'Memcached::addServer', 2, 'weight', 0)
            : 0;

        $state = VmMemcached::state($receiver);
        $state->servers[] = ['host' => $host, 'port' => $port, 'weight' => $weight];
        $state->resultCode = MemcachedConstants::RES_SUCCESS;

        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}
