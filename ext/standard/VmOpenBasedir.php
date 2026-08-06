<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;

/**
 * open_basedir INI + path gate (php-src main/fopen_wrappers.c — OnUpdateBaseDir / php_check_open_basedir).
 *
 * Shared by VM + JIT helpers (#28138). Empty value = unrestricted.
 */
final class VmOpenBasedir
{
    private static string $value = '';

    /** When a check denies, stream open failure detail is EPERM (Zend Operation not permitted). */
    private static bool $lastDenied = false;

    public static function get(): string
    {
        return self::$value;
    }

    /**
     * Runtime ini_set — tighten-only; empty new value fails (cannot clear).
     *
     * @return string|false previous value, or false on reject
     */
    public static function set(string $newValue): string|false
    {
        if ('' === $newValue) {
            return false;
        }

        $parts = \explode(\PATH_SEPARATOR, $newValue);
        $resolved = [];
        foreach ($parts as $part) {
            if ('' === $part) {
                continue;
            }
            $expanded = self::expandPath($part);
            if (false === $expanded) {
                return false;
            }
            // Proposed roots must themselves pass the current basedir (tighten-only).
            if (!self::pathAllowedSilent($expanded)) {
                return false;
            }
            $resolved[] = $expanded;
        }
        if ([] === $resolved) {
            return false;
        }

        $old = self::$value;
        self::$value = \implode(\PATH_SEPARATOR, $resolved);

        return $old;
    }

    public static function restore(): void
    {
        self::$value = '';
    }

    public static function isActive(): bool
    {
        return '' !== self::$value;
    }

    /**
     * @return bool true when the path is allowed (or basedir inactive)
     */
    public static function check(
        string $path,
        bool $warn = true,
        ?string $function = null,
        ?Context $ctx = null,
        ?Frame $frame = null
    ): bool {
        self::$lastDenied = false;
        if (!self::isActive()) {
            return true;
        }
        if (self::pathAllowedSilent($path)) {
            return true;
        }
        self::$lastDenied = true;
        if ($warn) {
            self::emitWarning($path, $function, $ctx, $frame);
        }

        return false;
    }

    /** Consume EPERM detail for Failed to open stream after a basedir denial. */
    public static function consumeDeniedOpenDetail(): ?string
    {
        if (!self::$lastDenied) {
            return null;
        }
        self::$lastDenied = false;

        return 'Operation not permitted';
    }

    public static function peekDenied(): bool
    {
        return self::$lastDenied;
    }

    private static function pathAllowedSilent(string $path): bool
    {
        if (!self::isActive()) {
            return true;
        }
        $resolved = self::expandPath($path);
        if (false === $resolved) {
            return false;
        }
        foreach (\explode(\PATH_SEPARATOR, self::$value) as $root) {
            if ('' === $root) {
                continue;
            }
            $rootResolved = self::expandPath($root);
            if (false === $rootResolved) {
                $rootResolved = \rtrim($root, "/\\");
            }
            if ($resolved === $rootResolved) {
                return true;
            }
            $prefix = $rootResolved.\DIRECTORY_SEPARATOR;
            if (\str_starts_with($resolved, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * expand_filepath-shaped resolution for basedir checks (realpath when possible).
     *
     * @return string|false
     */
    private static function expandPath(string $path): string|false
    {
        if ('' === $path) {
            return false;
        }
        $real = @\realpath($path);
        if (false !== $real) {
            return $real;
        }
        // Non-existent path: absolutize + collapse . / .. components (no symlink resolve).
        if (\str_starts_with($path, '/') || (1 < \strlen($path) && ':' === $path[1])) {
            $absolute = $path;
        } else {
            $cwd = \getcwd();
            if (false === $cwd) {
                return false;
            }
            $absolute = $cwd.\DIRECTORY_SEPARATOR.$path;
        }

        return self::normalizeAbsolute($absolute);
    }

    private static function normalizeAbsolute(string $path): string|false
    {
        $path = \str_replace('\\', '/', $path);
        $isAbs = \str_starts_with($path, '/');
        $parts = \explode('/', $path);
        $stack = [];
        foreach ($parts as $i => $part) {
            if ('' === $part || '.' === $part) {
                if (0 === $i && $isAbs) {
                    // keep leading empty for absolute
                }
                continue;
            }
            if ('..' === $part) {
                if ([] === $stack) {
                    continue;
                }
                \array_pop($stack);
                continue;
            }
            $stack[] = $part;
        }
        if (!$isAbs) {
            return false;
        }
        if ([] === $stack) {
            return '/';
        }

        return '/'.\implode('/', $stack);
    }

    private static function emitWarning(
        string $path,
        ?string $function,
        ?Context $ctx,
        ?Frame $frame
    ): void {
        $core = \sprintf(
            'open_basedir restriction in effect. File(%s) is not within the allowed path(s): (%s)',
            $path,
            self::$value
        );
        $message = null !== $function && '' !== $function
            ? $function.'(): '.$core
            : $core;
        if (null !== $frame && null !== $frame->vmContext) {
            $frame->vmContext->errors->triggerError(
                $message,
                ErrorReporter::E_WARNING,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $frame->vmContext,
                $frame
            );

            return;
        }
        if (null !== $ctx) {
            $ctx->errors->triggerError(
                $message,
                ErrorReporter::E_WARNING,
                null,
                $ctx
            );

            return;
        }
        TriggerErrorJitHelper::warning($message);
    }
}
