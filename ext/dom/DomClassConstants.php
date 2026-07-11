<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Variable;

/** Register builtin DOM class constants (php-src ext/dom; #14448). */
final class DomClassConstants
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
