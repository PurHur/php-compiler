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

final class WeakReferenceGet implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (count($args) < 1) {
            throw new \LogicException('WeakReference::get() requires $this');
        }
        WeakRefRuntime::ensureLinked($context);
        WeakRefNative::registerDeclarations($context);
        $weakRefObj = WeakRefSetup::loadObjectFromArg($context, $args[0]);
        $prop = $context->type->object->propertyFetch($weakRefObj, 'WeakReference', '__weak_target');
        $dest = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__object__load_value_slot'),
            $prop->objectPropertySlot,
            $dest
        );

        return $dest;
    }
}
