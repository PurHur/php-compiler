<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Socket builtin class — PHP-owned Socket wrapper (php-src ext/sockets/sockets.c; #6544, #6203).
 *
 * VM stores host {@see \Socket} or imported stream resources in side tables keyed by {@see ObjectEntry::$id}.
 */
final class VmSocket
{
    public const CLASS_LC = 'socket';

    /** @var list<string> php-src stream_type values accepted by socket_import_stream */
    private const SOCKET_STREAM_TYPES = [
        'tcp_socket',
        'unix_socket',
        'udp_socket',
        'ssl_socket',
    ];

    /** @var array<int, \Socket> */
    private static array $hostSockets = [];

    /** @var array<int, resource> */
    private static array $streamResources = [];

    /** @var array<int, int> */
    private static array $hostSocketFds = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('Socket');
        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function wrapHost(\Socket $host, Context $ctx): Variable
    {
        self::registerClass($ctx);
        $var = new Variable(Variable::TYPE_OBJECT);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$hostSockets[$object->id] = $host;
        $fd = self::resolveFdForHostSocket($host);
        if (null !== $fd) {
            self::$hostSocketFds[$object->id] = $fd;
        }
        $var->object($object);

        return $var;
    }

    public static function hostSocket(ObjectEntry $object): ?\Socket
    {
        return self::$hostSockets[$object->id] ?? null;
    }

    /** @return resource|null */
    public static function streamResource(ObjectEntry $object): mixed
    {
        return self::$streamResources[$object->id] ?? null;
    }

    public static function isValidSocketObject(ObjectEntry $object): bool
    {
        return isset(self::$hostSockets[$object->id]) || isset(self::$streamResources[$object->id]);
    }

    /**
     * socket_import_stream() — wrap a socket stream resource as Socket (php-src ext/sockets/sockets.c; #6203).
     *
     * @return Variable|false
     */
    public static function importStream(mixed $stream, Context $ctx): Variable|false
    {
        if (!\is_resource($stream)) {
            return false;
        }
        $meta = @stream_get_meta_data($stream);
        if (!\is_array($meta)) {
            return false;
        }
        $transport = $meta['stream_type'] ?? '';
        if (!\in_array($transport, self::SOCKET_STREAM_TYPES, true)) {
            return false;
        }

        return self::wrapStreamResource($stream, $ctx);
    }

    public static function wrapStreamResource(mixed $stream, Context $ctx): Variable
    {
        self::registerClass($ctx);
        $var = new Variable(Variable::TYPE_OBJECT);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$streamResources[$object->id] = $stream;
        $fd = self::fdForStreamResource($stream);
        if (null !== $fd) {
            self::$hostSocketFds[$object->id] = $fd;
        }
        $var->object($object);

        return $var;
    }

    public static function fdForObject(ObjectEntry $object): ?int
    {
        if (isset(self::$hostSocketFds[$object->id])) {
            return self::$hostSocketFds[$object->id];
        }
        $stream = self::$streamResources[$object->id] ?? null;
        if (\is_resource($stream)) {
            return self::fdForStreamResource($stream);
        }
        $host = self::$hostSockets[$object->id] ?? null;
        if ($host instanceof \Socket) {
            return self::resolveFdForHostSocket($host);
        }

        return null;
    }

    /** @param resource $stream */
    public static function fdForStreamResource(mixed $stream): ?int
    {
        foreach ([false, true] as $peer) {
            $name = @stream_socket_get_name($stream, $peer);
            if (!\is_string($name) || '' === $name) {
                continue;
            }
            foreach (VmSockets::enumerateSocketFds() as $fd => $_inode) {
                $got = '';
                if (!VmSockets::getsocknameFd($fd, $got)) {
                    continue;
                }
                if ($got === $name) {
                    return $fd;
                }
            }
        }

        return null;
    }

    public static function fdForHostSocket(\Socket $socket): ?int
    {
        foreach (self::$hostSockets as $id => $host) {
            if ($host === $socket && isset(self::$hostSocketFds[$id])) {
                return self::$hostSocketFds[$id];
            }
        }

        return self::resolveFdForHostSocket($socket);
    }

    public static function isSocketObject(?ObjectEntry $object): bool
    {
        return null !== $object && 0 === strcasecmp($object->class->name, 'Socket');
    }

    private static function resolveFdForHostSocket(\Socket $socket): ?int
    {
        if (!@socket_getsockname($socket, $addr)) {
            return null;
        }
        foreach (VmSockets::enumerateSocketFds() as $fd => $_inode) {
            $got = '';
            if (!VmSockets::getsocknameFd($fd, $got)) {
                continue;
            }
            if ($got === $addr) {
                return $fd;
            }
        }

        return null;
    }
}
