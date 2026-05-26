<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectType;
use PHPLLVM\Value;

/** C-floor Runtime::initCompiler — `new Compiler` without PHP CFG (#2568). */
final class RuntimeInitCompiler
{
    public static function emit(Context $context, ObjectType $object, Value $runtimeThis): void
    {
        $compilerId = $object->lookup('PHPCompiler\\Compiler');
        $compiler = $object->allocate($compilerId);
        $object->markObjectConstructed($compiler);
        $compilerVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $compiler);
        $compilerSlot = $object->propertyFetch($runtimeThis, 'PHPCompiler\\Runtime', 'compiler');
        $object->propertyStore($compilerSlot->objectPropertySlot, $compilerVar, Variable::TYPE_OBJECT);
    }
}
