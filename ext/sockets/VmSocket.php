<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmStreamMeta;

/**
 * Socket builtin class — PHP-owned Socket wrapper (php-src ext/sockets/sockets.c; #6544, #6203, #8202).
 *
 * Imported streams are tracked by VmFs handle id — no host Zend stream metadata delegation.
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

    /** @var array<int, resource> */
    private static array $streamResources = [];

    /** @var array<int, int> object id => VmFs stream handle */
    private static array $streamHandles = [];

    /** @var array<int, int> object id => dup(2) socket fd */
    private static array $hostSocketFds = [];

    /** @var array<int, int> JIT object handle (ptrToInt) => socket fd */
    private static array $jitHandleFds = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('Socket');
        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function hostSocket(ObjectEntry $object): ?\Socket
    {
        return null;
    }

    /** @return resource|null */
    public static function streamResource(ObjectEntry $object): mixed
    {
        return self::$streamResources[$object->id] ?? null;
    }

    public static function isValidSocketObject(ObjectEntry $object): bool
    {
        return isset(self::$streamResources[$object->id]);
    }

    /**
     * socket_import_stream() — wrap a VmFs socket stream handle as Socket (#6203, #8202).
     *
     * @return Variable|false
     */
    public static function importStreamHandle(int $handle, Context $ctx): Variable|false
    {
        if (!VmFs::isValidHandle($handle)) {
            return false;
        }

        $streamType = VmStreamMeta::streamTypeForUri(VmFs::handleUri($handle));
        if (null === $streamType || !\in_array($streamType, self::SOCKET_STREAM_TYPES, true)) {
            return false;
        }

        $stream = VmFs::lookupResource($handle);
        if (!\is_resource($stream)) {
            return false;
        }

        return self::wrapImportedStream($handle, $stream, $ctx);
    }

    private static function wrapImportedStream(int $handle, mixed $stream, Context $ctx): Variable
    {
        self::registerClass($ctx);
        $var = new Variable(Variable::TYPE_OBJECT);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$streamResources[$object->id] = $stream;
        self::$streamHandles[$object->id] = $handle;
        $fd = VmFs::socketFdForHandle($handle);
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
        $handle = self::$streamHandles[$object->id] ?? null;
        if (null !== $handle) {
            return VmFs::socketFdForHandle($handle);
        }

        return null;
    }

    public static function registerJitHandleFd(int $handle, int $fd): void
    {
        if ($handle > 0 && $fd >= 0) {
            self::$jitHandleFds[$handle] = $fd;
        }
    }

    public static function fdForLookupKey(int $key): ?int
    {
        if ($key <= 0) {
            return null;
        }
        if (isset(self::$jitHandleFds[$key])) {
            return self::$jitHandleFds[$key];
        }
        if (isset(self::$hostSocketFds[$key])) {
            return self::$hostSocketFds[$key];
        }
        if (isset(self::$streamHandles[$key])) {
            return VmFs::socketFdForHandle(self::$streamHandles[$key]);
        }
        if (1 === \count(self::$hostSocketFds)) {
            return (int) \reset(self::$hostSocketFds);
        }

        return null;
    }

    public static function isSocketObject(?ObjectEntry $object): bool
    {
        return null !== $object && 0 === strcasecmp($object->class->name, 'Socket');
    }
}
