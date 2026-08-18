<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;

/**
 * Script/include stream opens — allow_url_include gate (php-src main/streams/streams.c).
 *
 * php_strip_whitespace(), highlight_file(), show_source(), and include() use this policy;
 * fopen/file_get_contents use allow_url_fopen only (#32104).
 */
final class VmStreamIncludeOpenPolicy
{
    private const WRAPPER_DISABLED_DETAIL = 'no suitable wrapper could be found';

    /**
     * php-src php_stream_is_url — scheme:// after first path char (#32104).
     */
    public static function isUrlWrapper(string $path): bool
    {
        if ('' === $path) {
            return false;
        }

        return false !== \strpos($path, '://', 1);
    }

    public static function isAllowUrlIncludeEnabled(?Context $ctx = null): bool
    {
        if (null !== $ctx) {
            $raw = VmIni::get($ctx, 'allow_url_include');
            if (false === $raw) {
                return false;
            }

            return VmIni::parseBoolIni((string) $raw);
        }

        // Zend CLI default — allow_url_include off (ext/standard/ini.c).
        return false;
    }

    public static function blockedForScriptOpen(string $path, ?Context $ctx = null): bool
    {
        return self::isUrlWrapper($path) && !self::isAllowUrlIncludeEnabled($ctx);
    }

    public static function wrapperDisabledMessage(string $function, string $path): string
    {
        $scheme = self::schemeLabel($path);

        return \sprintf(
            '%s(): %s wrapper is disabled in the server configuration by allow_url_include=0',
            $function,
            $scheme
        );
    }

    public static function failedToOpenMessage(string $function, string $path): string
    {
        return \sprintf(
            '%s(%s): Failed to open stream: %s',
            $function,
            $path,
            self::WRAPPER_DISABLED_DETAIL
        );
    }

    public static function warnScriptOpenBlocked(
        Frame $frame,
        string $function,
        string $path,
        bool $forHighlight = false
    ): void {
        if (null === $frame->vmContext) {
            return;
        }
        self::emitWarnings(
            $frame->vmContext,
            $function,
            $path,
            $forHighlight,
            $frame->scriptPath,
            $frame
        );
    }

    public static function warnScriptOpenBlockedStandalone(
        string $function,
        string $path,
        bool $forHighlight = false
    ): void {
        $vm = \PHPCompiler\VM::running();
        $ctx = null !== $vm ? $vm->context : null;
        if (null === $ctx) {
            return;
        }
        self::emitWarnings($ctx, $function, $path, $forHighlight, $ctx->scriptStack->current(), null);
    }

    private static function emitWarnings(
        Context $ctx,
        string $function,
        string $path,
        bool $forHighlight,
        ?string $scriptFile,
        ?Frame $frame
    ): void {
        $file = \is_string($scriptFile) && '' !== $scriptFile ? $scriptFile : null;
        $ctx->errors->triggerError(
            self::wrapperDisabledMessage($function, $path),
            ErrorReporter::E_WARNING,
            $file,
            $ctx,
            $frame
        );
        $ctx->errors->triggerError(
            self::failedToOpenMessage($function, $path),
            ErrorReporter::E_WARNING,
            $file,
            $ctx,
            $frame
        );
        if ($forHighlight) {
            $ctx->errors->triggerError(
                VmStreamOpenFailure::highlightFailedOpeningMessage($function, $path),
                ErrorReporter::E_WARNING,
                $file,
                $ctx,
                $frame
            );
        }
    }

    private static function schemeLabel(string $path): string
    {
        $protocol = VmStreamWrapperRegistry::parseProtocol($path);

        return null !== $protocol ? $protocol.'://' : 'url://';
    }
}
