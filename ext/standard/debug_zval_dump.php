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
 * debug_zval_dump() — internal zval introspection (Zend/zend_builtin_functions.c, #6576).
 *
 * @see https://github.com/php/php-src/blob/master/Zend/zend_builtin_functions.c zif_debug_zval_dump
 * JIT/AOT: scalar lowering via JitDebugZvalDump → JitVarDump (#6084).
 */
final class debug_zval_dump extends Internal
{
    public function __construct()
    {
        parent::__construct('debug_zval_dump');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('debug_zval_dump() requires VM context');
        }
        $vm = $frame->vmContext->runtime->vm;
        if (null === $vm) {
            throw new \LogicException('debug_zval_dump() requires an active VM');
        }
        foreach ($frame->calledArgs as $arg) {
            self::dumpVariable($vm, $arg->resolveIndirect(), 0, false, $frame);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitDebugZvalDump::invoke($context, ...$args);
    }

    private static function write(string $chunk): void
    {
        OutputBuffer::append($chunk);
    }

    private static function dumpVariable(VM $vm, Variable $var, int $level, bool $showRefMarker = false, ?Frame $frame = null): void
    {
        TypedPropertyCheck::assertReadable($var);
        if ($level > 0) {
            self::write(str_repeat(' ', $level));
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
        self::write('array('.$count.') refcount('.$table->getGcRefcount()."){\n");
        foreach ($table->iterateKeyed(false) as [$key, $value]) {
            self::write(str_repeat(' ', $level + 1));
            self::write(self::formatKey($key)."\n");
            self::dumpVariable($vm, $value, $level + 2, true, $frame);
        }
        if ($level > 0) {
            self::write(str_repeat(' ', $level));
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
        self::write('object('.$object->class->name.')#'.$object->id.' ('.$count.') refcount('.$object->refCount."){\n");
        foreach ($props as $name => $value) {
            self::write(str_repeat(' ', $level + 1));
            self::write('["'.$name."\"]=>\n");
            self::dumpVariable($vm, $value, $level + 2, true, $frame);
        }
        if ($level > 0) {
            self::write(str_repeat(' ', $level));
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
