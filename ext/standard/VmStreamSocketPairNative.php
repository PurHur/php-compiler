<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stream_socket_pair for VM — {@see VmStreamSocketPairPure} SSOT (#3437, #12253).
 *
 * JIT/AOT: {@see JitStreamSocketPair} / __compiler_stream_socket_pair
 */
final class VmStreamSocketPairNative
{
    public static function available(): bool
    {
        return VmStreamSocketPairPure::available();
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int}|false
     */
    public static function pair(int $domain, int $type, int $protocol): array|false
    {
        return VmStreamSocketPairPure::pair($domain, $type, $protocol);
    }
}
