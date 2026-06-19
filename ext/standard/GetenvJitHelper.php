<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for getenv()/putenv() overlay (#9092, php-in-PHP).
 *
 * Process environ on miss stays in {@see \PHPCompiler\JIT\Builtin\StringGetenv} libc trampoline.
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
        if (!\array_key_exists($name, self::$local)) {
            return false;
        }

        return self::$local[$name];
    }

    /** @return array<string, string> */
    public static function getAllEnvironmentMap(): array
    {
        return self::$local;
    }

    public static function putenv(?string $assignment): bool
    {
        if (null === $assignment) {
            return false;
        }
        [$name, $value] = self::parseAssignment($assignment);
        if ('' === $name) {
            return false;
        }
        if (null === $value) {
            unset(self::$local[$name]);
        } else {
            self::$local[$name] = $value;
        }

        return true;
    }
}
