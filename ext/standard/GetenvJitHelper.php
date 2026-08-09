<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for getenv()/putenv() overlay (#9092, #8992, #20644, #23414 php-in-PHP).
 *
 * Overlay mutations in PHP; inherited environ via {@see GetenvLookupJitHelper}
 * (`@getenv` NestedJIT leaf — #29313). Process-environ setenv mirror
 * via {@see phpc_putenv_kernel} (no caller-side LibcExtern in JitEnv).
 * php-src: ext/standard/basic_functions.c — zif_getenv, zif_putenv
 */
final class GetenvJitHelper
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

    public static function getenv(?string $name, int $localOnly): string|false
    {
        if (null === $name) {
            return false;
        }
        if (PutenvJitHelper::hasOverlay($name)) {
            return PutenvJitHelper::lookupOverlay($name);
        }
        if (\array_key_exists($name, self::$local)) {
            return self::$local[$name];
        }
        if (0 !== $localOnly) {
            return false;
        }

        return GetenvLookupJitHelper::fromEnviron($name, 0) ?? false;
    }

    /** @return array<string, string> */
    public static function getAllEnvironmentMap(): array
    {
        $all = VmEnvEnvironNative::enumerate();
        foreach (self::$local as $name => $value) {
            if ('' !== $name) {
                $all[$name] = $value;
            }
        }
        foreach (PutenvJitHelper::localOverlayEntries() as $name => $value) {
            if ('' !== $name) {
                $all[$name] = $value;
            }
        }

        return $all;
    }

    public static function putenv(?string $assignment): bool
    {
        // Delegate to slim PutenvJitHelper NestedJIT leaf (#23414).
        return PutenvJitHelper::putenv($assignment);
    }

    public static function apacheSetenv(?string $variable, ?string $value): bool
    {
        if (null === $variable || null === $value) {
            return false;
        }

        return self::putenv($variable.'='.$value);
    }

    /** Merge process-local putenv overlay into a native hashtable (JIT/AOT edge, #13431, #5965). */
    public static function mergeLocalOverlayIntoNative(int $htPtr): void
    {
        foreach (self::$local as $name => $value) {
            if ('' === $name) {
                continue;
            }
            phpc_native_ht_set_string_key($htPtr, $name, $value);
        }
        // Putenv overlay — slim NestedJIT leaf (#23414 / #24855).
        PutenvJitHelper::mergeLocalOverlayIntoNative($htPtr);
    }

    /** Populate a native hashtable with inherited environ + local putenv overlay (JIT getenv argc==0, #5075, #20758). */
    public static function fillAllEnvironmentHashtable(int $htPtr): void
    {
        // Init-safe libc environ walk — NestedJIT of /proc enumerate segfaults under thin AOT (#19157 / #20758).
        VmEnvEnvironNative::mirrorIntoNativeHashtable($htPtr);
        self::mergeLocalOverlayIntoNative($htPtr);
    }

    /** @return array<string, string> VM overlay map for interpreter-side merge helpers. */
    public static function localOverlayEntries(): array
    {
        return self::$local + PutenvJitHelper::localOverlayEntries();
    }

    /** Merge process-local putenv overlay into a VM hashtable (interpreter path, #9814). */
    public static function mergeLocalOverlayInto(\PHPCompiler\VM\HashTable $ht): void
    {
        EnvLocalJitHelperVm::mergeLocalOverlayInto($ht);
    }
}
