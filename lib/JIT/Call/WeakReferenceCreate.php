<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\WeakRefNative;
use PHPCompiler\JIT\Builtin\WeakRefRuntime;
use PHPCompiler\JIT\Builtin\WeakRefSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

final class WeakReferenceCreate implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (count($args) < 1) {
            throw new \LogicException('WeakReference::create() expects the referent object');
        }
        WeakRefRuntime::ensureLinked($context);
        WeakRefNative::registerDeclarations($context);
        $classId = WeakRefSetup::requireClassId($context, 'WeakReference');
        $targetObj = WeakRefSetup::loadObjectFromArg($context, $args[0]);
        $weakRefObj = $context->type->object->allocate($classId);
        WeakRefSetup::bindWeakTarget($context, $weakRefObj, $targetObj);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $weakRefObj
        );

        return $slot;
    }
}
