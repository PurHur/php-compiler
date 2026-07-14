<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;
use PHPCompiler\Web\Superglobals;

/**
 * spl_autoload() / spl_autoload_extensions() for compiled JIT/AOT modules (#4256, php-in-PHP).
 *
 * SSOT: {@see VmSplAutoload::defaultAutoload()} / {@see VmSplAutoload::fileExtensions()}
 * php-src: ext/spl/php_spl.c — PHP_FUNCTION(spl_autoload), PHP_FUNCTION(spl_autoload_extensions)
 */
final class SplAutoloadDefaultJitHelper
{
    public static function defaultAutoloadArgv(string $className, bool $hasFileExts, ?string $fileExts): void
    {
        $ctx = self::requireActiveContext();
        VmSplAutoload::defaultAutoload(
            $ctx,
            $className,
            $hasFileExts ? $fileExts : null
        );
    }

    public static function extensionsArgv(bool $hasArg, ?string $fileExts): string
    {
        if ($hasArg && null !== $fileExts) {
            VmSplAutoload::setFileExtensions($fileExts);
        }

        return VmSplAutoload::fileExtensions();
    }

    private static function requireActiveContext(): Context
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException('spl_autoload() JIT helper requires active VM context (#4256)');
        }

        return $ctx;
    }
}
