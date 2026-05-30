<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Process environment helpers — putenv()/getenv() local table (php-src EG(env), issue #3710).
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

        return \putenv($setting);
    }

    public static function getenv(string $name, bool $localOnly = false): string|false
    {
        if ($localOnly) {
            if (!\array_key_exists($name, self::$local)) {
                return false;
            }

            return self::$local[$name];
        }

        $result = \getenv($name);

        return false === $result ? false : $result;
    }
}
