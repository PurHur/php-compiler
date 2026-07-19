<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zmq;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Pure-PHP ZeroMQ subset — inproc PAIR/context/socket/bind/connect/send/recv/poll (#6443).
 *
 * No libzmq / runtime/*.c growth: inproc:// is an in-process queue broker so the
 * phase-0 smoke (bind/connect PAIR) works without a native library.
 */
final class VmZmq
{
    public const CONTEXT_LC = 'zmqcontext';
    public const SOCKET_LC = 'zmqsocket';
    public const ZMQ_LC = 'zmq';
    public const EXCEPTION_LC = 'zmqexception';

    /** @var array<int, true> context object id */
    private static array $contexts = [];

    /** @var array<int, array{context: int, type: int, endpoint: ?string, role: ?string, peer: ?int, recv: list<string>}> */
    private static array $sockets = [];

    /** @var array<string, array{binder: ?int, connector: ?int}> */
    private static array $endpoints = [];

    public static function registerClasses(Context $ctx): void
    {
        self::registerException($ctx);
        self::registerZmq($ctx);
        self::registerContext($ctx);
        self::registerSocket($ctx);
    }

    private static function registerException(Context $ctx): void
    {
        if (isset($ctx->classes[self::EXCEPTION_LC])) {
            return;
        }
        $entry = new ClassEntry('ZMQException');
        if (isset($ctx->classes['exception'])) {
            $entry->parentLc = 'exception';
        }
        $entry->isInternal = true;
        $ctx->classes[self::EXCEPTION_LC] = $entry;
    }

    private static function registerZmq(Context $ctx): void
    {
        if (isset($ctx->classes[self::ZMQ_LC]) && isset($ctx->classes[self::ZMQ_LC]->constants['socket_pair'])) {
            return;
        }
        $entry = isset($ctx->classes[self::ZMQ_LC])
            ? $ctx->classes[self::ZMQ_LC]
            : new ClassEntry('ZMQ');
        $entry->isInternal = true;
        foreach (ZmqConstants::CLASS_CONSTANTS as $name => $value) {
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$name] = $const;
            $entry->constNames[$name] = ZmqConstants::CLASS_CONSTANT_NAMES[$name];
        }
        $ctx->classes[self::ZMQ_LC] = $entry;
    }

    private static function registerContext(Context $ctx): void
    {
        if (isset($ctx->classes[self::CONTEXT_LC])) {
            return;
        }
        $entry = new ClassEntry('ZMQContext');
        $entry->isInternal = true;
        $ctx->classes[self::CONTEXT_LC] = $entry;
    }

    private static function registerSocket(Context $ctx): void
    {
        if (isset($ctx->classes[self::SOCKET_LC])) {
            return;
        }
        $entry = new ClassEntry('ZMQSocket');
        $entry->isInternal = true;
        $ctx->classes[self::SOCKET_LC] = $entry;
    }

    public static function createContext(Context $ctx): ObjectEntry
    {
        self::registerClasses($ctx);
        $object = new ObjectEntry($ctx->classes[self::CONTEXT_LC]);
        $object->constructed = true;
        self::$contexts[$object->id] = true;

        return $object;
    }

    public static function isContext(ObjectEntry $object): bool
    {
        return isset(self::$contexts[$object->id]);
    }

    public static function createSocket(ObjectEntry $context, int $type, Context $ctx): ObjectEntry
    {
        if (!self::isContext($context)) {
            throw new \ZMQException('zmq_socket(): supplied resource is not a valid ZMQ context');
        }
        self::registerClasses($ctx);
        $object = new ObjectEntry($ctx->classes[self::SOCKET_LC]);
        $object->constructed = true;
        self::$sockets[$object->id] = [
            'context' => $context->id,
            'type' => $type,
            'endpoint' => null,
            'role' => null,
            'peer' => null,
            'recv' => [],
        ];

        return $object;
    }

    public static function isSocket(ObjectEntry $object): bool
    {
        return isset(self::$sockets[$object->id]);
    }

    public static function requireSocket(ObjectEntry $object, string $function): array
    {
        if (!self::isSocket($object)) {
            throw new \TypeError($function.'(): Argument #1 ($socket) must be of type ZMQSocket');
        }

        return self::$sockets[$object->id];
    }

    public static function bind(ObjectEntry $socket, string $endpoint): bool
    {
        if (!self::isSocket($socket)) {
            throw new \TypeError('zmq_bind(): Argument #1 ($socket) must be of type ZMQSocket');
        }
        if (!str_starts_with($endpoint, 'inproc://')) {
            throw new \ZMQException('zmq_bind(): only inproc:// endpoints are supported without libzmq (#6443)');
        }
        $state = &self::$sockets[$socket->id];
        if (null !== $state['endpoint']) {
            throw new \ZMQException('zmq_bind(): socket already bound or connected');
        }
        $slot = self::$endpoints[$endpoint] ?? ['binder' => null, 'connector' => null];
        if (null !== $slot['binder']) {
            throw new \ZMQException('zmq_bind(): address already in use: '.$endpoint);
        }
        $slot['binder'] = $socket->id;
        self::$endpoints[$endpoint] = $slot;
        $state['endpoint'] = $endpoint;
        $state['role'] = 'binder';
        self::linkPeers($endpoint);

        return true;
    }

    public static function connect(ObjectEntry $socket, string $endpoint): bool
    {
        if (!self::isSocket($socket)) {
            throw new \TypeError('zmq_connect(): Argument #1 ($socket) must be of type ZMQSocket');
        }
        if (!str_starts_with($endpoint, 'inproc://')) {
            throw new \ZMQException('zmq_connect(): only inproc:// endpoints are supported without libzmq (#6443)');
        }
        $state = &self::$sockets[$socket->id];
        if (null !== $state['endpoint']) {
            throw new \ZMQException('zmq_connect(): socket already bound or connected');
        }
        $slot = self::$endpoints[$endpoint] ?? ['binder' => null, 'connector' => null];
        if (null !== $slot['connector']) {
            throw new \ZMQException('zmq_connect(): endpoint already has a connector: '.$endpoint);
        }
        $slot['connector'] = $socket->id;
        self::$endpoints[$endpoint] = $slot;
        $state['endpoint'] = $endpoint;
        $state['role'] = 'connector';
        self::linkPeers($endpoint);

        return true;
    }

    private static function linkPeers(string $endpoint): void
    {
        $slot = self::$endpoints[$endpoint] ?? null;
        if (null === $slot || null === $slot['binder'] || null === $slot['connector']) {
            return;
        }
        $a = $slot['binder'];
        $b = $slot['connector'];
        if (!isset(self::$sockets[$a], self::$sockets[$b])) {
            return;
        }
        self::$sockets[$a]['peer'] = $b;
        self::$sockets[$b]['peer'] = $a;
    }

    public static function send(ObjectEntry $socket, string $message): bool
    {
        $state = self::requireSocket($socket, 'zmq_send');
        $peer = $state['peer'];
        if (null === $peer || !isset(self::$sockets[$peer])) {
            throw new \ZMQException('zmq_send(): socket is not connected');
        }
        self::$sockets[$peer]['recv'][] = $message;

        return true;
    }

    public static function recv(ObjectEntry $socket): string|false
    {
        if (!self::isSocket($socket)) {
            throw new \TypeError('zmq_recv(): Argument #1 ($socket) must be of type ZMQSocket');
        }
        $queue = &self::$sockets[$socket->id]['recv'];
        if ([] === $queue) {
            return false;
        }

        return array_shift($queue);
    }

    /**
     * zmq_poll() — PECL-shaped subset: list of [socket, events] → readable/writable masks.
     *
     * @param list<array{0: ObjectEntry, 1: int}> $items
     * @return list<array{0: ObjectEntry, 1: int}>
     */
    public static function poll(array $items, int $timeoutMs = -1): array
    {
        unset($timeoutMs); // inproc is non-blocking; timeout ignored for phase-0
        $ready = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item[0], $item[1])) {
                continue;
            }
            $sock = $item[0];
            $events = (int) $item[1];
            if (!$sock instanceof ObjectEntry || !self::isSocket($sock)) {
                continue;
            }
            $mask = 0;
            $state = self::$sockets[$sock->id];
            if (($events & ZmqConstants::POLL_IN) !== 0 && [] !== $state['recv']) {
                $mask |= ZmqConstants::POLL_IN;
            }
            if (($events & ZmqConstants::POLL_OUT) !== 0 && null !== $state['peer']) {
                $mask |= ZmqConstants::POLL_OUT;
            }
            if (0 !== $mask) {
                $ready[] = [$sock, $mask];
            }
        }

        return $ready;
    }

    /** Test/reset helper — clear static broker state between PHPT cases. */
    public static function resetForTests(): void
    {
        self::$contexts = [];
        self::$sockets = [];
        self::$endpoints = [];
    }
}
