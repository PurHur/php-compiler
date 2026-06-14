<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\OutputBuffer;
use PHPCompiler\VM\TypedPropertyCheck;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * var_dump() — debug export with __debugInfo hook (issues #3133, #3259).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/var.c php_var_dump
 */
final class var_dump_ extends Internal
{
    public function __construct()
    {
        parent::__construct('var_dump');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('var_dump() requires VM context');
        }
        $vm = $frame->vmContext->runtime->vm;
        if (null === $vm) {
            throw new \LogicException('var_dump() requires an active VM');
        }
        foreach ($frame->calledArgs as $arg) {
            self::dumpVariable($vm, $arg->resolveIndirect(), 1, false, $frame);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitVarDump::invoke($context, ...$args);
    }

    private static function write(string $chunk): void
    {
        OutputBuffer::append($chunk);
    }

    private static function dumpVariable(VM $vm, Variable $var, int $level, bool $showRefMarker = false, ?Frame $frame = null): void
    {
        TypedPropertyCheck::assertReadable($var);
        if ($level > 1) {
            self::write(str_repeat(' ', $level - 1));
        }
        if ($showRefMarker && Variable::TYPE_INDIRECT === $var->type) {
            self::write('&');
            $var = $var->resolveIndirect();
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            self::write('int('.$var->toInt().")\n");

            return;
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            self::write('float('.VmFloatDtoa::formatVarDump($var->toFloat()).")\n");

            return;
        }
        if (Variable::TYPE_STRING === $var->type) {
            self::write('string('.\strlen($var->toString()).') "'.$var->toString()."\"\n");

            return;
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            self::write('bool('.($var->toBool() ? 'true' : 'false').")\n");

            return;
        }
        if (Variable::TYPE_NULL === $var->type) {
            self::write("NULL\n");

            return;
        }
        $resourceDump = VmVarFormat::tryFormatVarDump($var);
        if (null !== $resourceDump) {
            self::write($resourceDump);

            return;
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            self::dumpArray($vm, $var->toArray(), $level, $frame);

            return;
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            self::dumpObject($vm, $var->toObject(), $level, $frame);

            return;
        }
        if (Variable::TYPE_ENUM_CASE === $var->type) {
            $case = $var->toEnumCase();
            self::write('enum('.$case->enumClass->name.'::'.$case->caseName.")\n");

            return;
        }

        self::write("unknown()\n");
    }

    private static function dumpArray(VM $vm, VM\HashTable $table, int $level, ?Frame $frame = null): void
    {
        $count = 0;
        foreach ($table->iterateKeyed(false) as $_) {
            ++$count;
        }
        self::write('array('.$count.") {\n");
        foreach ($table->iterateKeyed(false) as [$key, $value]) {
            self::write(str_repeat(' ', $level));
            self::write(self::formatKey($key)."\n");
            self::dumpVariable($vm, $value, $level + 1, true, $frame);
        }
        if ($level > 1) {
            self::write(str_repeat(' ', $level - 1));
        }
        self::write("}\n");
    }

    private static function dumpObject(VM $vm, VM\ObjectEntry $object, int $level, ?Frame $frame = null): void
    {
        if (EnumCaseSupport::isEnumCase($object)) {
            self::write('enum('.$object->class->name.'::'.($object->enumCaseName ?? '').")\n");

            return;
        }
        $props = $object->getProperties(ClassEntry::PROP_PURPOSE_DEBUG, $vm, $frame);
        $count = \count($props);
        self::write('object('.$object->class->name.')#'.$object->id.' ('.$count.") {\n");
        foreach ($props as $name => $value) {
            self::write(str_repeat(' ', $level));
            self::write('["'.$name."\"]=>\n");
            self::dumpVariable($vm, $value, $level + 1, true, $frame);
        }
        if ($level > 1) {
            self::write(str_repeat(' ', $level - 1));
        }
        self::write("}\n");
    }

    private static function formatKey(Variable $key): string
    {
        if (Variable::TYPE_INTEGER === $key->type) {
            return '['.$key->toInt().']=>';
        }

        return '["'.$key->toString().'"]=>';
    }
}
