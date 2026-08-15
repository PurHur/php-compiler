<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

/**
 * NestedJIT-safe socket_cmsg_space() — pure CMSG_SPACE math (#31345).
 *
 * Keep this TU free of Frame / FFI so thin AOT NestedJIT stays small.
 * Same ANCILLARY table + CMSG_ALIGN/SPACE math as the VM path (#6333).
 * php-src: ext/sockets/sendrecvmsg.c — PHP_FUNCTION(socket_cmsg_space)
 */
final class SocketCmsgSpaceJitHelper
{
    /** @var array<string, array{size: int, var_el_size: int}> */
    private const ANCILLARY = [
        '1:1' => ['size' => 0, 'var_el_size' => 4],   // SOL_SOCKET + SCM_RIGHTS
        '1:2' => ['size' => 12, 'var_el_size' => 0],  // SOL_SOCKET + SCM_CREDENTIALS
    ];

    /** LLVM i64 ABI — CMSG_SPACE for (level, type, num). */
    public static function cmsgSpaceArgv(int $level, int $type, int $num): int
    {
        $key = $level.':'.$type;
        if (!isset(self::ANCILLARY[$key])) {
            throw new \ValueError(
                'Pair level '.$level.' and/or type '.$type.' is not supported'
            );
        }
        if ($num < 0) {
            throw new \ValueError(
                'socket_cmsg_space(): Argument #3 ($num) must be greater than or equal to 0'
            );
        }
        $entry = self::ANCILLARY[$key];
        $dataLen = $entry['size'] + $num * $entry['var_el_size'];

        return self::cmsgAlign($dataLen) + self::cmsgAlign(16);
    }

    /** CMSG_ALIGN — Linux glibc (#6333). */
    private static function cmsgAlign(int $len): int
    {
        $align = 8; // sizeof(size_t) on x86_64

        return ($len + $align - 1) & ~($align - 1);
    }
}
