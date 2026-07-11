<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Variable;

/**
 * Register builtin SPL class constants for defined() / ReflectionClass::getConstants().
 *
 * php-src: ext/spl — ZEND_ACC_CONSTANT on internal classes (#13134).
 */
final class SplClassConstants
{
    /**
     * @param array<string, int> $constants
     */
    public static function registerIntConstants(ClassEntry $entry, array $constants): void
    {
        foreach ($constants as $name => $value) {
            $lc = strtolower($name);
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$lc] = $const;
            $entry->constNames[$lc] = $name;
        }
    }
}
