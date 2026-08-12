<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * nl_langinfo() for compiled JIT/AOT modules (#30404, php-in-PHP).
 *
 * Leaf is `\nl_langinfo` → NestedJIT whitelist {@see nl_langinfo} →
 * {@see \PHPCompiler\JIT\Builtin\StringNlLanginfo} → {@see JitNlLanginfo} libc leaf
 * (fnmatch #30383 / time #30332 / gethostname #29364 shape).
 * Returns null for php false so the LLVM bridge boxes bool false.
 * Invalid-item Warning is emitted by the NestedJIT libc leaf (#29459); under thin AOT
 * it is recorded in error_get_last (stderr print for NestedJIT helper TUs is best-effort).
 * php-src: ext/standard/nl_langinfo.c — PHP_FUNCTION(nl_langinfo)
 */
final class NlLanginfoJitHelper
{
    public static function nlLanginfoArgv(int $item): ?string
    {
        $result = \nl_langinfo($item);

        return false === $result ? null : $result;
    }
}
