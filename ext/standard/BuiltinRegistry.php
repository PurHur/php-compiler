<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Func\Internal;

/**
 * Authoritative sorted builtin function name list (issues #5390, #1216, #6400).
 *
 * SSOT for function_exists() JIT helper (FunctionExistsJitHelper) and AOT/JIT
 * symbol resolution — replaces lib/AOT/runtime/builtin_function_names.inc.
 *
 * php-src: ext/standard/basic_functions.c — function_exists() registry
 */
final class BuiltinRegistry
{
    /** @var list<string>|null */
    private static ?array $sortedNames = null;

    /** @var array<string, Internal>|null */
    private static ?array $byName = null;

    /**
     * @return list<string> Lowercase sorted builtin names from ext Module registrations.
     */
    public static function sortedNames(): array
    {
        if (null !== self::$sortedNames) {
            return self::$sortedNames;
        }

        $names = [];
        foreach ([new Module(), new \PHPCompiler\ext\types\Module()] as $module) {
            foreach ($module->getFunctions() as $func) {
                $names[] = strtolower($func->getName());
            }
        }
        $names = array_values(array_unique($names));
        sort($names);
        self::$sortedNames = $names;

        return self::$sortedNames;
    }

    /**
     * Resolve a registered ext/* Internal handler by name (#6543).
     *
     * php-src: zend_call_known_function for string callbacks in array_find family.
     */
    public static function resolve(string $name): ?Internal
    {
        $lc = strtolower($name);
        if (null === self::$byName) {
            self::$byName = [];
            foreach ([new Module(), new \PHPCompiler\ext\types\Module()] as $module) {
                foreach ($module->getFunctions() as $func) {
                    if (!$func instanceof Internal) {
                        continue;
                    }
                    self::$byName[strtolower($func->getName())] = $func;
                }
            }
        }

        return self::$byName[$lc] ?? null;
    }
}
