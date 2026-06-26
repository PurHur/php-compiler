<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stream_set_blocking without a dedicated fcntl FFI module (#12251).
 *
 * Host-adopted streams use Zend stream_set_blocking; VmPhpFdStream fds use fcntl on the
 * existing VmPhpFdStream libc FFI table.
 *
 * php-src: ext/standard/streams.c — php_stream_set_blocking
 */
final class VmStreamBlockingPure
{
    public static function available(): bool
    {
        return \function_exists('stream_set_blocking') || VmPhpFdStream::available();
    }

    public static function setBlocking(int $fd, bool $mode): bool
    {
        if ($fd < 0) {
            return true;
        }

        $handle = VmFs::findHandleIdForSocketFd($fd);
        if (null !== $handle) {
            $fp = VmFs::lookupResource($handle);
            if (\is_resource($fp)) {
                return @\stream_set_blocking($fp, $mode);
            }
        }

        if (VmPhpFdStream::ownsFd($fd)) {
            return VmPhpFdStream::setBlockingOnFd($fd, $mode);
        }

        return false;
    }
}
