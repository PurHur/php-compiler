<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ClassConstName;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Variable;

/** Register builtin DOM class constants (php-src ext/dom; #14448, #26060). */
final class DomClassConstants
{
    /**
     * @param array<string, int> $constants
     */
    public static function registerIntConstants(ClassEntry $entry, array $constants): void
    {
        foreach ($constants as $name => $value) {
            // Case-sensitive storage keys (#25910) — strtolower keys are Reflection phantoms (#26060).
            $key = ClassConstName::key($name);
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$key] = $const;
            $entry->constNames[$key] = $name;
        }
    }
}
