<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Socket builtin class — host ext/sockets object wrapper (php-src ext/sockets/sockets.c; #6544).
 *
 * VM stores the host {@see \Socket} in a side table keyed by {@see ObjectEntry::$id}.
 */
final class VmSocket
{
    public const CLASS_LC = 'socket';

    /** @var array<int, \Socket> */
    private static array $hostSockets = [];

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
