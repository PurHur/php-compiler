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
}
