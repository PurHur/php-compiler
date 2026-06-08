<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Zend scalar casts on enum case operands for intval/floatval/settype (#5623, #5643, ext/standard/type.c).
 */
final class VmScalarType
{
    public static function tryEnumCaseToInt(?Frame $frame, Variable $value): ?int
    {
        return EnumCaseSupport::tryCastToInt($value, $frame?->vmContext, $frame);
    }

    public static function tryEnumCaseToFloat(?Frame $frame, Variable $value): ?float
    {
        return EnumCaseSupport::tryCastToFloat($value, $frame?->vmContext, $frame);
    }

    public static function isEnumCaseVariable(Variable $value): bool
    {
        return null !== EnumCaseSupport::enumClassForCaseVariable($value);
    }

    /**
     * Zend settype($x, 'array') on enum case — ['name' => case, 'value' => backing] (#5643, type.c).
     */
    public static function enumCaseToSettypeArray(Variable $value): ?HashTable
    {
        $entry = EnumCaseSupport::enumCaseEntryForVariable($value);
        if (null === $entry) {
            return null;
        }
        $ht = new HashTable();
        $name = new Variable();
        $name->string($entry->caseName);
        $ht->add('name', $name);
        if (null !== $entry->enumClass->backedType) {
            $backing = new Variable();
            $backing->copyFrom($entry->backingValue);
            $ht->add('value', $backing);
        }

        return $ht;
    }
}
