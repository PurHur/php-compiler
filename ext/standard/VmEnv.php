<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Process environment helpers — putenv()/getenv() local table (php-src EG(env), issue #3710).
 *
 * Inherited variables come from {@see VmEnvEnvironNative}; no host Zend or libc FFI (#8086, #8992).
 *
 * php-src: ext/standard/basic_functions.c — zif_putenv, zif_getenv
 */
final class VmEnv
{
    public const PUTENV_INVALID_SYNTAX_ERROR = 'putenv(): Argument #1 ($assignment) must have a valid syntax';

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

    /**
     * @throws \ValueError when assignment has empty name or other invalid syntax (#10335, php-src zif_putenv)
     */
    public static function assertValidPutenvSyntax(string $setting): void
    {
        [$name] = self::parsePutenvAssignment($setting);
        if ('' === $name) {
            throw new \ValueError(self::PUTENV_INVALID_SYNTAX_ERROR);
        }
    }

    public static function putenv(string $setting): bool
    {
        self::assertValidPutenvSyntax($setting);
        [$name, $value] = self::parsePutenvAssignment($setting);
        if (null === $value) {
            unset(self::$local[$name]);
        } else {
            self::$local[$name] = $value;
        }

        VmEnvPutenvNative::putenv($setting);

        return true;
    }

    public static function getenv(string $name, bool $localOnly = false): string|false
    {
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
