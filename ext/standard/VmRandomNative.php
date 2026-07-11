<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * CSPRNG for VM — pure PHP default ({@see VmRandomPure}, #8921, #12181).
 *
 * No libc getrandom/open/read FFI on the default path — shrinks native link surface for self-host/M5.
 *
 * Mirrors {@see \PHPCompiler\JIT\Builtin\StringRandomBytes} (/dev/urandom LLVM read) and
 * {@see VmFsReadNative} stream read patterns. php-src: ext/standard/random.c — php_random_bytes()
 */
final class VmRandomNative
{
    public static function available(): bool
    {
        return VmRandomPure::available();
    }

    /**
     * @throws \Exception when the operating system cannot supply random data
     */
    public static function randomBytes(int $length): string
    {
        return VmRandomPure::randomBytes($length);
    }
}
