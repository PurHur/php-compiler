<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsStdio;
use PHPCompiler\ext\standard\VmPhpFdStream;
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
        'tcp_socket/ssl',
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

    /** @var array<int, true> object ids whose fd is owned by socket_create() (#19286) */
    private static array $ownedFds = [];

    /** @var array<int, int> object id => AF_* domain (php_sock->type; #20268) */
    private static array $domains = [];

    /** @var array<int, int> JIT object handle (ptrToInt) => socket fd */
    private static array $jitHandleFds = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('Socket');
        $entry->isInternal = true;
        // php-src `final class Socket` (ext/sockets/sockets.stub.php; #28391).
        $entry->isFinal = true;
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
        return isset(self::$streamResources[$object->id])
            || isset(self::$hostSocketFds[$object->id]);
    }

    /**
     * socket_create() — wrap an owned BSD socket fd as Socket (#19286).
     *
     * @param int|null $domain AF_* family stored as php_sock->type (#20268)
     */
    public static function wrapOwnedFd(int $fd, Context $ctx, ?int $domain = null): ObjectEntry
    {
        self::registerClass($ctx);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$hostSocketFds[$object->id] = $fd;
        self::$ownedFds[$object->id] = true;
        if (null !== $domain) {
            self::$domains[$object->id] = $domain;
        }

        return $object;
    }

    /** AF_* domain for bind/connect dispatch (php-src php_sock->type). */
    public static function domainForObject(ObjectEntry $object): ?int
    {
        return self::$domains[$object->id] ?? null;
    }

    public static function setDomainForObject(ObjectEntry $object, int $domain): void
    {
        self::$domains[$object->id] = $domain;
    }

    public static function ownedFdForObject(ObjectEntry $object): ?int
    {
        if (!isset(self::$ownedFds[$object->id])) {
            return null;
        }

        return self::$hostSocketFds[$object->id] ?? null;
    }

    public static function release(ObjectEntry $object): void
    {
        unset(
            self::$streamResources[$object->id],
            self::$streamHandles[$object->id],
            self::$hostSocketFds[$object->id],
            self::$ownedFds[$object->id],
            self::$domains[$object->id],
            self::$jitHandleFds[$object->id]
        );
    }

    /**
     * socket_import_stream() — wrap a VmFs socket stream handle as Socket (#6203, #8202).
     *
     * @return Variable|false
     */
    public static function canImportStreamHandle(int $handle): bool
    {
        if (!VmFs::isValidHandle($handle)) {
            return false;
        }

        $streamType = self::streamTypeForImport($handle);
        if (null === $streamType || !\in_array($streamType, self::SOCKET_STREAM_TYPES, true)) {
            return false;
        }

        // Host FILE* streams (stream_socket_*) or VmPhpFdStream from socket_export_stream (#28139).
        if (\is_resource(VmFs::lookupResource($handle))) {
            return true;
        }

        return VmPhpFdStream::isValidHandle($handle)
            && null !== VmPhpFdStream::fdForHandle($handle);
    }

    /** php-src ext/sockets/sockets.c — import follows stream_type, not php:// URI alone (#19996). */
    public static function importFailureMessage(int $handle): string
    {
        if ($handle > 0) {
            $uri = VmFs::handleUri($handle);
            if ('MEMORY' === VmStreamMeta::phpNativeStreamType($uri)) {
                return 'socket_import_stream(): Cannot represent a stream of type MEMORY as a Socket Descriptor';
            }
            if (VmFsStdio::isStdioUri($uri) && null === self::streamTypeForImport($handle)) {
                return 'socket_import_stream(): Cannot represent a stream of type STDIO as a Socket Descriptor';
            }
        }

        return 'socket_import_stream(): Unable to import stream';
    }

    private static function streamTypeForImport(int $handle): ?string
    {
        if (!VmFs::isValidHandle($handle)) {
            return null;
        }

        $uri = VmFs::handleUri($handle);
        $transportType = VmStreamMeta::streamTypeForUri($uri);
        if (null !== $transportType) {
            return $transportType;
        }

        $stream = VmFs::lookupResource($handle);
        if (\is_resource($stream)) {
            return VmStreamMeta::stdioInheritedStreamType($uri, $stream);
        }

        return null;
    }

    public static function importStreamHandle(int $handle, Context $ctx): Variable|false
    {
        if (!self::canImportStreamHandle($handle)) {
            return false;
        }

        return self::wrapImportedStream($handle, VmFs::lookupResource($handle), $ctx);
    }

    /** Register JIT-allocated Socket object keyed by object address (#9217, #28139). */
    public static function registerJitImportedStream(int $objAddr, int $streamHandle): void
    {
        if ($objAddr <= 0 || !self::canImportStreamHandle($streamHandle)) {
            return;
        }

        $stream = VmFs::lookupResource($streamHandle);
        if (\is_resource($stream)) {
            self::$streamResources[$objAddr] = $stream;
        }
        self::$streamHandles[$objAddr] = $streamHandle;
        $fd = self::socketFdForImportableHandle($streamHandle);
        if (null !== $fd) {
            self::$hostSocketFds[$objAddr] = $fd;
        }
    }

    private static function socketFdForImportableHandle(int $handle): ?int
    {
        $fd = VmFs::socketFdForHandle($handle);
        if (null !== $fd) {
            return $fd;
        }

        return VmFsStdio::stdioFdForUri(VmFs::handleUri($handle));
    }

    private static function wrapImportedStream(int $handle, mixed $stream, Context $ctx): Variable
    {
        self::registerClass($ctx);
        $var = new Variable(Variable::TYPE_OBJECT);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        if (\is_resource($stream)) {
            self::$streamResources[$object->id] = $stream;
        }
        self::$streamHandles[$object->id] = $handle;
        $fd = self::socketFdForImportableHandle($handle);
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

    /**
     * socket_create() under JIT/AOT — owned BSD fd keyed by object address (#27394).
     *
     * @param int|null $domain AF_* family stored as php_sock->type
     */
    public static function registerJitOwnedFd(int $objAddr, int $fd, ?int $domain = null): void
    {
        if ($objAddr <= 0 || $fd < 0) {
            return;
        }
        self::$hostSocketFds[$objAddr] = $fd;
        self::$ownedFds[$objAddr] = true;
        self::$jitHandleFds[$objAddr] = $fd;
        if (null !== $domain) {
            self::$domains[$objAddr] = $domain;
        }
    }

    public static function ownedFdForLookupKey(int $key): ?int
    {
        if ($key <= 0 || !isset(self::$ownedFds[$key])) {
            return null;
        }

        return self::$hostSocketFds[$key] ?? self::$jitHandleFds[$key] ?? null;
    }

    public static function releaseForLookupKey(int $key): void
    {
        if ($key <= 0) {
            return;
        }
        unset(
            self::$streamResources[$key],
            self::$streamHandles[$key],
            self::$hostSocketFds[$key],
            self::$ownedFds[$key],
            self::$domains[$key],
            self::$jitHandleFds[$key]
        );
    }

    public static function existingStreamHandleForLookupKey(int $key): ?int
    {
        if ($key <= 0) {
            return null;
        }
        $handle = self::$streamHandles[$key] ?? null;
        if (null === $handle) {
            return null;
        }
        if (!VmFs::isValidHandle($handle)) {
            unset(self::$streamHandles[$key]);

            return null;
        }

        return $handle;
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

    /**
     * Existing import/export VmFs handle only — does not allocate (#22542).
     * Use {@see streamHandleForObject()} when export may need to wrap a created fd.
     */
    public static function existingStreamHandleForObject(ObjectEntry $object): ?int
    {
        $handle = self::$streamHandles[$object->id] ?? null;
        if (null === $handle) {
            return null;
        }
        if (!VmFs::isValidHandle($handle)) {
            unset(self::$streamHandles[$object->id]);

            return null;
        }

        return $handle;
    }

    public static function streamHandleForObject(ObjectEntry $object): ?int
    {
        $handle = self::existingStreamHandleForObject($object);
        if (null !== $handle) {
            return $handle;
        }

        // socket_create() sockets have a live fd but no import stream — wrap for export (#22542).
        return self::ensureExportStreamHandle($object->id, $object);
    }

    /** VM object id or JIT object address (ptrToInt). */
    public static function streamHandleForLookupKey(int $key): ?int
    {
        if ($key <= 0) {
            return null;
        }
        $handle = self::$streamHandles[$key] ?? null;
        if (null !== $handle) {
            if (VmFs::isValidHandle($handle)) {
                return $handle;
            }
            unset(self::$streamHandles[$key]);
        }

        return self::ensureExportStreamHandle($key, null);
    }

    /**
     * socket_export_stream() — VmFs stream for imported or created Socket (#6349, #22542).
     *
     * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_export_stream)
     * wraps php_sock->bsd_socket in a php_stream (including socket_create fds).
     *
     * @return Variable|false
     */
    public static function exportStream(ObjectEntry $object, Context $ctx): Variable|false
    {
        $handle = self::streamHandleForObject($object);
        if (null === $handle) {
            return false;
        }
        $var = new Variable();
        $var->streamHandle($handle, $ctx);

        return $var;
    }

    /**
     * Allocate a VmPhpFdStream over a live socket fd when export has no import handle (#22542).
     *
     * Shares the Socket's fd (no dup) so fclose(stream)/socket_close stay coupled like Zend.
     */
    private static function ensureExportStreamHandle(int $key, ?ObjectEntry $object): ?int
    {
        $fd = null !== $object ? self::fdForObject($object) : self::fdForLookupKey($key);
        if (null === $fd || $fd < 0) {
            return null;
        }
        if (!VmPhpFdStream::available()) {
            return null;
        }

        $domain = null !== $object
            ? self::domainForObject($object)
            : (self::$domains[$key] ?? null);
        $uri = self::exportStreamUri($fd, $domain);
        $handle = VmPhpFdStream::adopt($fd, $uri, 'r+');
        if (false === $handle) {
            return null;
        }

        VmFs::registerStreamPath($handle, $uri);
        VmFs::registerStreamMode($handle, 'r+');
        // socketFdForHandle prefers VmPhpFdStream::fdForHandle; keep map for findHandleIdForSocketFd.
        self::$streamHandles[$key] = $handle;
        if (null !== $object) {
            self::$streamHandles[$object->id] = $handle;
        }

        return $handle;
    }

    /** URI scheme → stream_type via VmStreamMeta::streamTypeForUri (tcp/udp/unix_socket). */
    private static function exportStreamUri(int $fd, ?int $domain): string
    {
        if (VmSockets::AF_UNIX === $domain) {
            return 'unix://socket_export';
        }
        // SOL_SOCKET=1, SO_TYPE=3 (Linux; SocketConstants::registeredConstants)
        $sockType = SocketsLibcThinAbi::getsockoptInt($fd, 1, 3);
        if (SocketConstants::SOCK_DGRAM === $sockType) {
            return 'udp://socket_export';
        }

        return 'tcp://socket_export';
    }
}
