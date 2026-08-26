<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Script;

/**
 * Historical #4938 guard — intentionally a no-op (#35153).
 *
 * #4938 assumed Zend rejects `yield` / `: Generator` in static methods. PHP 8.2.32
 * (and current php-src) accepts them; the prior compile fatal was a false positive
 * that blocked valid programs. Kept as a named hook so spine inventory stays stable.
 *
 * php-src: Zend/zend_generators.c — static method generators are valid
 */
final class GeneratorStaticMethodCompileCheck
{
    public static function validate(Script $script): void
    {
        // Zend parity: do not reject static method generators (#35153 / re-#4938).
    }
}
