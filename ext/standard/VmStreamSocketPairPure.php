<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stream_socket_pair without libc socketpair FFI (#12253, pairs #8953 VmStreamSocketPure).
 *
 * Bootstrap path: host stream_socket_pair under Zend VM + VmFs::adoptStreamResource.
 *
 * php-src: ext/standard/streams.c — PHP_FUNCTION(stream_socket_pair)
 */
final class VmStreamSocketPairPure
{
    public static function available(): bool
    {
        return \function_exists('stream_socket_pair');
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int}|false
     */
    public static function pair(int $domain, int $type, int $protocol): array|false
    {
        if (!self::available()) {
            return false;
        }
        if (!self::isSupportedTriple($domain, $type, $protocol)) {
            return false;
        }

        $pair = @\stream_socket_pair($domain, $type, $protocol);
        if (false === $pair || !isset($pair[0], $pair[1])) {
            return false;
        }

        $uri = 'unix://stream_socket_pair';
        $handle0 = VmFs::adoptStreamResource($pair[0], $uri);
        $handle1 = VmFs::adoptStreamResource($pair[1], $uri);
        if (false === $handle0 || false === $handle1) {
            @\fclose($pair[0]);
            @\fclose($pair[1]);

            return false;
        }

        return [
            $handle0,
            $handle1,
            VmFs::socketFdForHandle($handle0) ?? -1,
            VmFs::socketFdForHandle($handle1) ?? -1,
        ];
    }

    private static function isSupportedTriple(int $domain, int $type, int $protocol): bool
    {
        if (StdlibConstants::STREAM_PF_UNIX === $domain) {
            return true;
        }

        if (StdlibConstants::STREAM_PF_INET === $domain && StdlibConstants::STREAM_SOCK_STREAM === $type) {
            return 0 === $protocol || StdlibConstants::STREAM_IPPROTO_IP === $protocol;
        }

        return false;
    }
}
