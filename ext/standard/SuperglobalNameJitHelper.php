<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * __compiler_is_superglobal_name for compiled JIT/AOT modules (#9271, php-in-PHP).
 *
 * SSOT: {@see SuperglobalNames::isSuperglobalName()}
 * php-src: Zend/zend_compile.c — zend_is_auto_global_str
 */
final class SuperglobalNameJitHelper
{
    /** @return int 1 when superglobal name, 0 otherwise (LLVM i64 ABI) */
    public static function isSuperglobalName(string $name): int
    {
        return SuperglobalNames::isSuperglobalName($name) ? 1 : 0;
    }
}
