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
use PHPCompiler\VM\TypedPropertyCheck;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * debug_zval_dump() — internal zval introspection (Zend/zend_builtin_functions.c, #6576).
 *
 * @see https://github.com/php/php-src/blob/master/Zend/zend_builtin_functions.c zif_debug_zval_dump
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
        throw new \LogicException('debug_zval_dump() is VM-only in this compiler build (issue #6576)');
    }

    private static function dumpVariable(VM $vm, Variable $var, int $level, bool $showRefMarker = false, ?Frame $frame = null): void
    {
        TypedPropertyCheck::assertReadable($var);
        if ($level > 0) {
            echo str_repeat(' ', $level);
        }
        if ($showRefMarker && Variable::TYPE_INDIRECT === $var->type) {
            echo '&';
            $var = $var->resolveIndirect();
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            echo 'int(', $var->toInt(), ")\n";

            return;
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            echo 'float(', $var->toFloat(), ")\n";

            return;
        }
        if (Variable::TYPE_STRING === $var->type) {
            echo 'string(', \strlen($var->toString()), ') "', $var->toString(), "\"\n";

            return;
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            echo 'bool(', $var->toBool() ? 'true' : 'false', ")\n";

            return;
        }
        if (Variable::TYPE_NULL === $var->type) {
            echo "NULL\n";

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
            echo 'enum('.$case->enumClass->name.'::'.$case->caseName.")\n";

            return;
        }

        echo "unknown()\n";
    }

    private static function dumpArray(VM $vm, VM\HashTable $table, int $level, ?Frame $frame = null): void
    {
        $count = 0;
        foreach ($table->iterateKeyed(false) as $_) {
            ++$count;
        }
        echo 'array(', $count, ') refcount(', $table->getGcRefcount(), "){\n";
        foreach ($table->iterateKeyed(false) as [$key, $value]) {
            echo str_repeat(' ', $level + 1);
            echo self::formatKey($key), "\n";
            self::dumpVariable($vm, $value, $level + 2, true, $frame);
        }
        if ($level > 0) {
            echo str_repeat(' ', $level);
        }
        echo "}\n";
    }

    private static function dumpObject(VM $vm, VM\ObjectEntry $object, int $level, ?Frame $frame = null): void
    {
        if (EnumCaseSupport::isEnumCase($object)) {
            echo 'enum('.$object->class->name.'::'.($object->enumCaseName ?? '').")\n";

            return;
        }
        $props = $object->getProperties(ClassEntry::PROP_PURPOSE_DEBUG, $vm, $frame);
        $count = \count($props);
        echo 'object(', $object->class->name, ')#', $object->id, ' (', $count, ') refcount(', $object->refCount, "){\n";
        foreach ($props as $name => $value) {
            echo str_repeat(' ', $level + 1);
            echo '["', $name, "\"]=>\n";
            self::dumpVariable($vm, $value, $level + 2, true, $frame);
        }
        if ($level > 0) {
            echo str_repeat(' ', $level);
        }
        echo "}\n";
    }

    private static function formatKey(Variable $key): string
    {
        if (Variable::TYPE_INTEGER === $key->type) {
            return '['.$key->toInt().']=>';
        }

        return '["'.$key->toString().'"]=>';
    }
}
