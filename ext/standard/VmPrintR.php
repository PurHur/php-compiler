<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\TypedPropertyCheck;
use PHPCompiler\VM\Variable;

/**
 * print_r() formatting SSOT (ext/standard/var.c parity, #9190).
 *
 * Shared by VM {@see print_r} and JIT {@see PrintRJitHelper}.
 */
final class VmPrintR
{
    public static function formatVariable(
        VM $vm,
        Variable $var,
        int $level = 0,
        ?Frame $frame = null
    ): string {
        /** @var \SplObjectStorage<int, true> $visited */
        $visited = new \SplObjectStorage();

        return self::formatNested($vm, $var, $level, $frame, $visited);
    }

    /**
     * @param \SplObjectStorage<int, true> $visited
     */
    private static function formatNested(
        VM $vm,
        Variable $var,
        int $level,
        ?Frame $frame,
        \SplObjectStorage $visited
    ): string {
        TypedPropertyCheck::assertReadable($var);
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER === $var->type) {
            return (string) $var->toInt();
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            return self::formatFloat($var->toFloat());
        }
        if (Variable::TYPE_STRING === $var->type) {
            return $var->toString();
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool() ? '1' : '';
        }
        if (Variable::TYPE_NULL === $var->type) {
            return '';
        }
        $resourceOut = VmVarFormat::tryFormatPrintR($var);
        if (null !== $resourceOut) {
            return $resourceOut;
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            return self::formatArray($vm, $var->toArray(), $level, $frame, $visited);
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            return self::formatObject($vm, $var->toObject(), $level, $frame, $visited);
        }
        if (Variable::TYPE_ENUM_CASE === $var->type) {
            return self::formatEnumCase($vm, $var->toEnumCase(), $level, $frame, $visited);
        }

        return '';
    }

    /** Zend zend_print_zval_r enum branch (ext/standard/var.c, #5608). */
    private static function formatEnumCase(
        VM $vm,
        VM\EnumCaseEntry $case,
        int $level,
        ?Frame $frame,
        \SplObjectStorage $visited
    ): string {
        $openSpaces = 0 === $level ? '' : str_repeat(' ', 4 * ($level + 1));
        $keySpaces = str_repeat(' ', 4 * (0 === $level ? 1 : $level + 2));
        $header = $case->enumClass->name.' Enum';
        if (null !== $case->enumClass->backedType) {
            $header .= ':'.$case->enumClass->backedType;
        }
        $lines = ["{$header}\n", "{$openSpaces}(\n"];
        $lines[] = $keySpaces.'[name] => '.$case->caseName."\n";
        if (null !== $case->enumClass->backedType) {
            $valueFormatted = self::formatNested(
                $vm,
                $case->backingValue->resolveIndirect(),
                $level + 1,
                $frame,
                $visited
            );
            $lines[] = $keySpaces.'[value] => '.$valueFormatted."\n";
        }
        $lines[] = "{$openSpaces})\n";

        return implode('', $lines);
    }

    /** php-src ext/standard/var.c — zend_print_zval double branch (#10470, #10933). */
    private static function formatFloat(float $value): string
    {
        return VmPrintRFloat::format($value);
    }

    /**
     * @param \SplObjectStorage<int, true> $visited
     */
    private static function formatArray(
        VM $vm,
        VM\HashTable $table,
        int $level,
        ?Frame $frame,
        \SplObjectStorage $visited
    ): string {
        if ($visited->contains($table)) {
            return self::formatArrayRecursionMarker();
        }
        $visited->attach($table);
        try {
            $openSpaces = 0 === $level ? '' : str_repeat(' ', 4 * ($level + 1));
            $keySpaces = str_repeat(' ', 4 * (0 === $level ? 1 : $level + 2));
            $lines = ["Array\n", "{$openSpaces}(\n"];
            foreach ($table->iterateKeyed(true) as [$key, $value]) {
                $formatted = self::formatNested($vm, $value->resolveIndirect(), $level + 1, $frame, $visited);
                $lines[] = "{$keySpaces}".self::formatKey($key).' => '.$formatted."\n";
            }
            $lines[] = "{$openSpaces})\n";

            return implode('', $lines);
        } finally {
            $visited->detach($table);
        }
    }

    /**
     * @param \SplObjectStorage<int, true> $visited
     */
    private static function formatObject(
        VM $vm,
        VM\ObjectEntry $object,
        int $level,
        ?Frame $frame,
        \SplObjectStorage $visited
    ): string {
        if ($visited->contains($object)) {
            return self::formatObjectRecursionMarker($object->class->name);
        }
        $visited->attach($object);
        try {
            $openSpaces = 0 === $level ? '' : str_repeat(' ', 4 * ($level + 1));
            $keySpaces = str_repeat(' ', 4 * (0 === $level ? 1 : $level + 2));
            $props = $object->getProperties(ClassEntry::PROP_PURPOSE_DEBUG, $vm, $frame);
            $lines = ["{$object->class->name} Object\n", "{$openSpaces}(\n"];
            foreach ($props as $name => $value) {
                $formatted = self::formatNested($vm, $value->resolveIndirect(), $level + 1, $frame, $visited);
                $lines[] = "{$keySpaces}[{$name}] => ".$formatted."\n";
            }
            $lines[] = "{$openSpaces})\n";

            return implode('', $lines);
        } finally {
            $visited->detach($object);
        }
    }

    /** php-src ext/standard/var.c — HASH_IS_APPLYING recursion marker (#11179). */
    private static function formatArrayRecursionMarker(): string
    {
        return "Array\n *RECURSION*";
    }

    /** php-src ext/standard/var.c — object recursion marker (#11179). */
    private static function formatObjectRecursionMarker(string $className): string
    {
        return "{$className} Object\n *RECURSION*";
    }

    private static function formatKey(Variable $key): string
    {
        if (Variable::TYPE_INTEGER === $key->type) {
            return '['.$key->toInt().']';
        }

        return '['.$key->toString().']';
    }
}
