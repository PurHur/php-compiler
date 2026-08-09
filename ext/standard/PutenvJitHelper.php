<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Slim putenv() NestedJIT leaf (#23414, #29334) — peer {@see GetenvLookupJitHelper} / {@see RenameJitHelper}.
 *
 * Owns process-local overlay + `@putenv` process-environ mirror so user-script AOT
 * does not NestedJIT the full GetenvJitHelper.php TU (apacheSetenv crash on master).
 * NestedJIT whitelist routes `putenv` → {@see JitEnv::putenvNestedLeaf} (kernel deleted).
 * php-src: ext/standard/basic_functions.c — zif_putenv
 */
final class PutenvJitHelper
{
    /** @var array<string, string> */
    private static array $local = [];

    /**
     * @return array{0: string, 1: ?string}
     */
    private static function parseAssignment(string $setting): array
    {
        $eq = strpos($setting, '=');
        if (false === $eq) {
            return [$setting, null];
        }

        return [substr($setting, 0, $eq), substr($setting, $eq + 1)];
    }

    /** Local syntax guard — avoid NestedJIT of VmEnv.php (#23414). */
    private static function assertValidSyntax(string $setting): void
    {
        [$name] = self::parseAssignment($setting);
        if ('' === $name) {
            throw new \ValueError('putenv(): Argument #1 ($assignment) must have a valid syntax');
        }
    }

    public static function putenv(?string $assignment): bool
    {
        if (null === $assignment) {
            return false;
        }
        self::assertValidSyntax($assignment);
        [$name, $value] = self::parseAssignment($assignment);
        if (null === $value) {
            unset(self::$local[$name]);
        } else {
            self::$local[$name] = $value;
        }
        // Full assignment — NestedJIT leaf mirrors via setenv/unsetenv (#29334 / #5965).
        @\putenv($assignment);

        return true;
    }

    /** Overlay hit for getenv($name, true) / merge helpers. */
    public static function lookupOverlay(string $name): string|false
    {
        if (!\array_key_exists($name, self::$local)) {
            return false;
        }

        return self::$local[$name];
    }

    public static function hasOverlay(string $name): bool
    {
        return \array_key_exists($name, self::$local);
    }

    /** @return array<string, string> */
    public static function localOverlayEntries(): array
    {
        return self::$local;
    }

    /**
     * Merge putenv overlay into a native hashtable (JIT/AOT getenv argc==0, #13431 / #24855).
     *
     * Lives here — not GetenvJitHelper — so NestedJIT does not compile that larger TU (#23414).
     */
    public static function mergeLocalOverlayIntoNative(int $htPtr): void
    {
        foreach (self::$local as $name => $value) {
            if ('' === $name) {
                continue;
            }
            phpc_native_ht_set_string_key($htPtr, $name, $value);
        }
    }
}
