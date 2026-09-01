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
        // NestedJIT helper TU compiles this file alone — do not static-call SuperglobalNames
        // (include order leaves the method unregistered under self-host stubs — #36142).
        // Keep cases in sync with ext/standard/SuperglobalNames.php ALL list (#36142).
        switch ($name) {
            case 'GLOBALS':
            case '_GET':
            case '_POST':
            case '_SERVER':
            case '_REQUEST':
            case '_COOKIE':
            case '_ENV':
            case '_FILES':
            case '_SESSION':
                return 1;
            default:
                return 0;
        }
    }
}
