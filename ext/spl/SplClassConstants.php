<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ClassConstName;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Variable;

/**
 * Register builtin SPL class constants for defined() / ReflectionClass::getConstants().
 *
 * php-src: ext/spl — ZEND_ACC_CONSTANT on internal classes (#13134).
 * Keys are case-sensitive ({@see ClassConstName::key}, #25910 / #26374).
 */
final class SplClassConstants
{
    /**
     * @param array<string, int> $constants
     */
    public static function registerIntConstants(ClassEntry $entry, array $constants): void
    {
        foreach ($constants as $name => $value) {
            $key = ClassConstName::key($name);
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$key] = $const;
            $entry->constNames[$key] = $name;
        }
    }
}
