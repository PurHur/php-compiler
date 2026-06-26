<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM popen/pclose — pure PHP via {@see VmPopenPure} (#8951, #12266).
 *
 * php-src: ext/standard/exec.c — PHP_FUNCTION(popen), PHP_FUNCTION(pclose)
 * JIT/AOT: {@see JitPopen} / __compiler_popen via StreamIoJit.
 */
final class VmPopenNative
{
    public static function available(): bool
    {
        return VmPopenPure::available();
    }

    /**
     * @return array{handle: int, file: int}|false file is a pure-path pclose token
     */
    public static function open(string $command, string $mode): array|false
    {
        return VmPopenPure::open($command, $mode);
    }

    public static function pclose(int $token): int
    {
        return VmPopenPure::pclose($token);
    }
}
