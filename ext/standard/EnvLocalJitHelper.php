<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * putenv()/getenv() local overlay for compiled JIT/AOT modules (#9814, php-in-PHP).
 *
 * SSOT: {@see GetenvJitHelper} static overlay storage.
 * php-src: ext/standard/basic_functions.c — EG(env)
 */
final class EnvLocalJitHelper
{
    public static function lookupOverlay(string $name): string|false
    {
        return GetenvJitHelper::getenv($name, 1);
    }

    public static function registerPutenv(string $assignment): bool
    {
        return GetenvJitHelper::putenv($assignment);
    }

    public static function mergeOverlay(HashTable $ht): void
    {
        foreach (GetenvJitHelper::getAllEnvironmentMap() as $name => $value) {
            $slot = new Variable();
            $slot->string($value);
            $ht->add($name, $slot);
        }
    }
}
