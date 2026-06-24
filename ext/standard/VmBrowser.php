<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;

/**
 * get_browser() VM helper — browscap gate (ext/standard/browscap.c parity, #11172).
 *
 * php-src: ext/standard/browscap.c — php_get_browser
 */
final class VmBrowser
{
    public const BROWSCAP_NOT_SET_WARNING = 'get_browser(): browscap ini directive not set';

    public static function browscapIniPath(Context $ctx): string|false
    {
        $path = VmIni::get($ctx, 'browscap');
        if (false === $path || '' === $path) {
            return false;
        }

        return $path;
    }

    public static function browscapConfigured(Context $ctx): bool
    {
        $path = self::browscapIniPath($ctx);

        return false !== $path && is_readable($path);
    }

    public static function triggerBrowscapNotSetWarning(Context $ctx, ?string $file, ?\PHPCompiler\Frame $frame): void
    {
        $ctx->errors->triggerError(
            self::BROWSCAP_NOT_SET_WARNING,
            ErrorReporter::E_WARNING,
            $file,
            $ctx,
            $frame
        );
    }
}
