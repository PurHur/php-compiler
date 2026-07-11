<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * fputcsv() field cell coercion (php-src ext/standard/file.c zval_get_string; #12447).
 */
final class VmFputcsv
{
    public static function coerceFieldCell(Variable $value): string
    {
        $value = $value->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($value)) {
            $enumClass = EnumCaseSupport::enumClassForCaseVariable($value);

            throw new \Error(
                'Object of class '.($enumClass->name ?? 'enum').' could not be converted to string'
            );
        }
        if (Variable::TYPE_OBJECT === $value->type) {
            $vm = VM::running();
            $object = $value->toObject();
            if (null === $vm || !$vm->hasInstanceMethod($object->class, '__tostring')) {
                throw new \Error(
                    'Object of class '.$object->class->name.' could not be converted to string'
                );
            }
        }

        return VmString::coerceOperand($value);
    }

    /**
     * @param iterable<Variable> $fields
     *
     * @return list<string>
     */
    public static function coerceFieldList(iterable $fields): array
    {
        $cells = [];
        foreach ($fields as $value) {
            $cells[] = self::coerceFieldCell($value);
        }

        return $cells;
    }
}
