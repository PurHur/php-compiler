<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\WeakRefNative;
use PHPCompiler\JIT\Builtin\WeakRefRuntime;
use PHPCompiler\JIT\Builtin\WeakRefSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * WeakReference::get() — JIT/AOT (#1366, #30925).
 *
 * php-src: Zend/zend_weakrefs.stub.php — get(): ?object
 */
final class WeakReferenceGet implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (count($args) < 1) {
            throw new \LogicException('WeakReference::get() requires $this');
        }
        // php-src ZEND_PARSE_PARAMETERS(0); $args[0] is $this (#30925)
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage(
                    'WeakReference::get',
                    0,
                    $userArgCount
                )
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'weakref_get_argc_cont');
            $slot = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $slot)
            );

            return JitValueBox::pointer($context, $slot);
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
