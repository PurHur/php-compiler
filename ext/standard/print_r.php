<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\OutputBuffer;
use PHPCompiler\VM\TypedPropertyCheck;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * print_r() — human-readable debug output (ext/standard/var.c parity, #3133).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/basic_functions.c PHP_FUNCTION(print_r)
 */
final class print_r extends Internal
{
    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('print_r() requires VM context');
        }
        $vm = $frame->vmContext->runtime->vm;
        if (null === $vm) {
            throw new \LogicException('print_r() requires an active VM');
        }
        $argc = count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('print_r() expects 1 or 2 arguments');
        }
        $return = false;
        if (2 === $argc) {
            $return = $frame->calledArgs[1]->resolveIndirect()->toBool();
        }
        $out = self::formatVariable($vm, $frame->calledArgs[0]->resolveIndirect(), 0, $frame);
        if ($return) {
            if (null === $frame->returnVar) {
                return;
            }
            $frame->returnVar->string($out);

            return;
        }
        if ('' !== $out) {
            OutputBuffer::append($out);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitPrintR::invoke($context, ...$args);
    }

    private static function formatVariable(VM $vm, Variable $var, int $level, ?Frame $frame = null): string
    {
        TypedPropertyCheck::assertReadable($var);
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
            return self::formatArray($vm, $var->toArray(), $level, $frame);
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            return self::formatObject($vm, $var->toObject(), $level, $frame);
        }
        if (Variable::TYPE_ENUM_CASE === $var->type) {
            return self::formatEnumCase($vm, $var->toEnumCase(), $level, $frame);
        }

        return '';
    }

    /** Zend zend_print_zval_r enum branch (ext/standard/var.c, #5608). */
    private static function formatEnumCase(
        VM $vm,
        VM\EnumCaseEntry $case,
        int $level,
        ?Frame $frame = null
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
            $valueFormatted = self::formatVariable(
                $vm,
                $case->backingValue->resolveIndirect(),
                $level + 1,
                $frame
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

    private static function formatArray(VM $vm, VM\HashTable $table, int $level, ?Frame $frame = null): string
    {
        $openSpaces = 0 === $level ? '' : str_repeat(' ', 4 * ($level + 1));
        $keySpaces = str_repeat(' ', 4 * (0 === $level ? 1 : $level + 2));
        $lines = ["Array\n", "{$openSpaces}(\n"];
        foreach ($table->iterateKeyed(true) as [$key, $value]) {
            $formatted = self::formatVariable($vm, $value->resolveIndirect(), $level + 1, $frame);
            $lines[] = "{$keySpaces}".self::formatKey($key).' => '.$formatted."\n";
        }
        $lines[] = "{$openSpaces})\n";

        return implode('', $lines);
    }

    private static function formatObject(VM $vm, VM\ObjectEntry $object, int $level, ?Frame $frame = null): string
    {
        $openSpaces = 0 === $level ? '' : str_repeat(' ', 4 * ($level + 1));
        $keySpaces = str_repeat(' ', 4 * (0 === $level ? 1 : $level + 2));
        $props = $object->getProperties(ClassEntry::PROP_PURPOSE_DEBUG, $vm, $frame);
        $lines = ["{$object->class->name} Object\n", "{$openSpaces}(\n"];
        foreach ($props as $name => $value) {
            $formatted = self::formatVariable($vm, $value->resolveIndirect(), $level + 1);
            $lines[] = "{$keySpaces}[{$name}] => ".$formatted."\n";
        }
        $lines[] = "{$openSpaces})\n";

        return implode('', $lines);
    }

    private static function formatKey(Variable $key): string
    {
        if (Variable::TYPE_INTEGER === $key->type) {
            return '['.$key->toInt().']';
        }

        return '['.$key->toString().']';
    }
}
