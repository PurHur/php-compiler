<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Process environment helpers — putenv()/getenv() local table (php-src EG(env), issue #3710).
 *
 * VM syncs the process environment via {@see VmEnvPutenvNative} (libc FFI) — no host Zend delegation (#8086).
 *
 * php-src: ext/standard/basic_functions.c — zif_putenv, zif_getenv
 */
final class VmEnv
{
    /** @var array<string, string> Variables set or overridden via putenv() in this process */
    private static array $local = [];

    /**
     * @return array{0: string, 1: ?string} name and value; value null means unset (no '=' in assignment)
     */
    public static function parsePutenvAssignment(string $setting): array
    {
        $eq = strpos($setting, '=');
        if (false === $eq) {
            return [$setting, null];
        }

        return [substr($setting, 0, $eq), substr($setting, $eq + 1)];
    }

    public static function putenv(string $setting): bool
    {
        [$name, $value] = self::parsePutenvAssignment($setting);
        if ('' === $name) {
            return false;
        }
        if (null === $value) {
            unset(self::$local[$name]);
        } else {
            self::$local[$name] = $value;
        }

        return VmEnvPutenvNative::putenv($setting);
    }

    public static function getenv(string $name, bool $localOnly = false): string|false
    {
        if ($localOnly) {
            // php-src zif_getenv: local_only skips sapi_getenv, uses php_getenv (overlay + environ).
            if (\array_key_exists($name, self::$local)) {
                return self::$local[$name];
            }
            $environ = VmEnvEnvironNative::enumerate();
            if (\array_key_exists($name, $environ)) {
                return $environ[$name];
            }

            return false;
        }

        if (\array_key_exists($name, self::$local)) {
            return self::$local[$name];
        }

        return VmEnvPutenvNative::getenv($name);
    }

    /**
     * @return array<string, string> getenv() zero-arg assoc map (#5075, #9092).
     */
    public static function exportAllEnvironmentMap(): array
    {
        return self::getAllEnvironmentMap();
    }

    /**
     * getenv() with no arguments — assoc array of all variables (#5075, php-src zif_getenv argc==0).
     */
    public static function getAllEnvironmentTable(): HashTable
    {
        $ht = new HashTable();
        foreach (self::getAllEnvironmentMap() as $name => $value) {
            $var = new Variable();
            $var->string($value);
            $ht->add($name, $var);
        }

        return $ht;
    }

    /**
     * @return array<string, string>
     */
    private static function getAllEnvironmentMap(): array
    {
        $all = VmEnvEnvironNative::enumerate();
        foreach (self::$local as $name => $value) {
            if ('' !== $name) {
                $all[$name] = $value;
            }
        }

        return $all;
    }
}
