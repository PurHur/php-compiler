<?php

declare(strict_types=1);

namespace PHPCompiler;

/** Runtime identity strings for phpversion() / php_sapi_name() (issue #3174). */
final class CompilerVersion
{
    /** Language/runtime version reported by phpversion() (php-src PHP_VERSION shape). */
    public const VERSION = '8.2.0-dev';

    /** SAPI name for CLI entrypoints (bin/vm.php, AOT binaries). */
    public const SAPI = 'cli';

    /** PHP 8.3+ typed class constants in traits (Zend/zend_compile.c, issue #5212). */
    public static function supportsTypedTraitConstants(): bool
    {
        return version_compare(self::VERSION, '8.3.0', '>=');
    }

    /** PHP 8.3+ str_increment() / str_decrement() (ext/standard/string.c, issue #5697). */
    public static function supportsStrIncrement(): bool
    {
        return version_compare(self::VERSION, '8.3.0', '>=');
    }
}
