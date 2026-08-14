<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\WeakRefNative;
use PHPCompiler\JIT\Builtin\WeakRefRuntime;
use PHPCompiler\JIT\Builtin\WeakRefSetup;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

final class WeakReferenceCreate implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve (#24592). */
    public string $name = 'WeakReference::create';

    /** @var list<string> php-src Zend/zend_weakrefs.stub.php */
    public array $paramNames = ['object'];

    /** Static factory — no implicit $this (#24592). */
    public int $namedArgsReceiverPrefix = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        // Static — no implicit $this (php-src zim_WeakReference_create, #30867).
        $argc = \count($args);
        if (1 !== $argc) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('WeakReference::create() expects exactly 1 argument, %d given', $argc)
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'weakreference_create_argc_cont');
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        WeakRefRuntime::ensureLinked($context);
        WeakRefNative::registerDeclarations($context);
        $classId = WeakRefSetup::requireClassId($context, 'WeakReference');
        $targetObj = WeakRefSetup::loadObjectFromArg($context, $args[0]);
        $weakRefObj = $context->type->object->allocate($classId);
        WeakRefSetup::bindWeakTarget($context, $weakRefObj, $targetObj);
        // AOT call/assign retains one extra strong ref on the referent; drop it so the
        // WeakReference slot stays non-owning like VM weakObject (#26795 / zend_weakrefs.c).
        $context->refcount->delref($targetObj);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $weakRefObj
        );

        return $slot;
    }
}
