<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;

/**
 * User stream wrapper protocol registry (php-src main/streams; issues #3383, #6818).
 *
 * PHP-in-PHP: no phpc_stream.c wrapper table — custom protocols live here.
 */
final class VmStreamWrapperRegistry
{
    /** @var list<string> Built-in schemes always reported by stream_get_wrappers(). */
    private const BUILTIN_PROTOCOLS = [
        'file',
        'http',
        'https',
        'ftp',
        'ftps',
        'php',
        'compress.zlib',
        'data',
        'glob',
        'phar',
    ];

    /** @var array<string, string> lowercase protocol => wrapper class name */
    private static array $custom = [];

    /** @var array<string, list<string|null>> protocol => stack of prior class names (null = removed) */
    private static array $restoreStack = [];

    /** @var array<string, true> built-in protocols removed via stream_wrapper_unregister() */
    private static array $removedBuiltins = [];

    public const NOTICE_RESTORE_UNCHANGED = 'stream_wrapper_restore(): "%s" was never changed, nothing to restore';

    /**
     * php-src user_stream_register_wrapper — reject unknown class names (#12534).
     */
    public static function requireValidWrapperClass(Frame $frame, string $className): void
    {
        $ctx = VmReflection::requireContext($frame);
        if (!VmReflection::classExists($ctx, $className)) {
            throw new \TypeError(\sprintf(
                'stream_wrapper_register(): Argument #2 ($class) must be a valid class name, %s given',
                $className
            ));
        }
    }

    public static function register(string $protocol, string $className): bool
    {
        $key = self::normalizeProtocol($protocol);
        if ('' === $key || isset(self::$custom[$key])) {
            return false;
        }
        self::$custom[$key] = $className;

        return true;
    }

    public static function unregister(string $protocol): bool
    {
        $key = self::normalizeProtocol($protocol);
        if ('' === $key) {
            return false;
        }
        if (isset(self::$custom[$key])) {
            self::$restoreStack[$key][] = self::$custom[$key];
            unset(self::$custom[$key]);

            return true;
        }
        if (!self::isBuiltin($key) || isset(self::$removedBuiltins[$key])) {
            return false;
        }
        self::$restoreStack[$key][] = null;
        self::$removedBuiltins[$key] = true;

        return true;
    }

    public static function restore(string $protocol, ?Frame $frame = null): bool
    {
        $key = self::normalizeProtocol($protocol);
        if ('' === $key) {
            return false;
        }
        if (!isset(self::$restoreStack[$key]) || [] === self::$restoreStack[$key]) {
            if (self::isBuiltin($key) && !isset(self::$removedBuiltins[$key])) {
                self::triggerRestoreUnchangedNotice($frame, $key);

                return true;
            }

            return false;
        }
        $prior = \array_pop(self::$restoreStack[$key]);
        if ([] === self::$restoreStack[$key]) {
            unset(self::$restoreStack[$key]);
        }
        if (null === $prior) {
            unset(self::$custom[$key], self::$removedBuiltins[$key]);

            return true;
        }
        if (isset(self::$custom[$key])) {
            return false;
        }
        self::$custom[$key] = $prior;

        return true;
    }

    /** @return list<string> */
    public static function getWrappers(): array
    {
        $all = [];
        foreach (self::BUILTIN_PROTOCOLS as $protocol) {
            if (!isset(self::$removedBuiltins[$protocol])) {
                $all[] = $protocol;
            }
        }
        foreach (\array_keys(self::$custom) as $protocol) {
            $all[] = $protocol;
        }
        \sort($all);

        return $all;
    }

    public static function lookupClass(string $protocol): ?string
    {
        $key = self::normalizeProtocol($protocol);

        return self::$custom[$key] ?? null;
    }

    public static function parseProtocol(string $uri): ?string
    {
        if (!\str_contains($uri, '://')) {
            return null;
        }
        $protocol = \strtolower((string) \strstr($uri, '://', true));
        if ('' === $protocol) {
            return null;
        }

        return $protocol;
    }

    public static function isCustomProtocol(string $uri): bool
    {
        $protocol = self::parseProtocol($uri);
        if (null === $protocol) {
            return false;
        }

        return isset(self::$custom[$protocol]);
    }

    private static function normalizeProtocol(string $protocol): string
    {
        $protocol = \strtolower(\trim($protocol));
        if ('' === $protocol || !\preg_match('/^[a-z][a-z0-9+.-]*$/', $protocol)) {
            return '';
        }

        return $protocol;
    }

    private static function isBuiltin(string $protocol): bool
    {
        return \in_array($protocol, self::BUILTIN_PROTOCOLS, true);
    }

    private static function triggerRestoreUnchangedNotice(?Frame $frame, string $protocol): void
    {
        if (null === $frame || null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            \sprintf(self::NOTICE_RESTORE_UNCHANGED, $protocol),
            ErrorReporter::E_NOTICE,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame,
            $frame->callSiteLine
        );
    }

    /** @internal PHPUnit isolation */
    public static function resetForTests(): void
    {
        self::$custom = [];
        self::$restoreStack = [];
        self::$removedBuiltins = [];
    }
}
