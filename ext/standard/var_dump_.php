<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM;
use PHPCompiler\VM\ClassEntry;
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
            self::dumpVariable($vm, $arg->resolveIndirect(), 1);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('var_dump() is not implemented for JIT in this compiler build');
    }

    private static function dumpVariable(VM $vm, Variable $var, int $level): void
    {
        if ($level > 1) {
            echo str_repeat(' ', $level - 1);
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
            self::dumpArray($vm, $var->toArray(), $level);

            return;
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            self::dumpObject($vm, $var->toObject(), $level);

            return;
        }

        echo "unknown()\n";
    }

    private static function dumpArray(VM $vm, VM\HashTable $table, int $level): void
    {
        $count = 0;
        foreach ($table->iterateKeyed(true) as $_) {
            ++$count;
        }
        echo 'array(', $count, ") {\n";
        foreach ($table->iterateKeyed(true) as [$key, $value]) {
            echo str_repeat(' ', $level);
            echo self::formatKey($key), "\n";
            self::dumpVariable($vm, $value->resolveIndirect(), $level + 1);
        }
        if ($level > 1) {
            echo str_repeat(' ', $level - 1);
        }
        echo "}\n";
    }

    private static function dumpObject(VM $vm, VM\ObjectEntry $object, int $level): void
    {
        $props = $object->getProperties(ClassEntry::PROP_PURPOSE_DEBUG, $vm);
        $count = \count($props);
        echo 'object(', $object->class->name, ')#', $object->id, ' (', $count, ") {\n";
        foreach ($props as $name => $value) {
            echo str_repeat(' ', $level);
            echo '["', $name, "\"]=>\n";
            self::dumpVariable($vm, $value->resolveIndirect(), $level + 1);
        }
        if ($level > 1) {
            echo str_repeat(' ', $level - 1);
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
