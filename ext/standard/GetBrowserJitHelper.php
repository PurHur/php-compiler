<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * get_browser() for compiled JIT/AOT modules (#11172, php-in-PHP).
 *
 * SSOT: {@see VmBrowser::browscapConfigured()}
 * php-src: ext/standard/browscap.c — PHP_FUNCTION(get_browser)
 */
final class GetBrowserJitHelper
{
    /**
     * Returns 1 when browscap ini points at a readable file, 0 otherwise.
     * Full browscap DB parsing is deferred — VM/JIT both return false until then.
     */
    public static function browscapConfigured(): int
    {
        $path = @ini_get('browscap');

        return is_string($path) && '' !== $path && is_readable($path) ? 1 : 0;
    }
}
