<?php
declare(strict_types=1);
namespace PHPCompiler\JIT;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectType;
use PHPLLVM\Value;
final class RuntimeInitVmContext {
    public static function emit(Context $context, ObjectType $object, Value $runtimeThis): void {
        $ctxId = $object->lookup('PHPCompiler\\VM\\Context');
        $ctx = $object->allocate($ctxId);
        $runtimeVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $runtimeThis);
        $runtimeSlot = $object->propertyFetch($ctx, 'PHPCompiler\\VM\\Context', 'runtime');
        $object->propertyStore($runtimeSlot->objectPropertySlot, $runtimeVar, Variable::TYPE_OBJECT);
        $ctxVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $ctx);
        $vmContextSlot = $object->propertyFetch($runtimeThis, 'PHPCompiler\\Runtime', 'vmContext');
        $object->propertyStore($vmContextSlot->objectPropertySlot, $ctxVar, Variable::TYPE_OBJECT);
    }
}
