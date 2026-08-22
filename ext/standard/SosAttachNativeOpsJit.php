<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * NestedJIT → LLVM: attach empty stdClass + info into SplObjectStorage `__spl_ht` (#33876).
 *
 * php-src: ext/spl/spl_observer.c — spl_object_storage_attach
 */
final class SosAttachNativeOpsJit
{
    public static function attachEmptyStdClassNull(Context $context, JITVariable $htPtr): void
    {
        $ht = self::htFromI64($context, $htPtr);
        $obj = self::allocEmptyStdClass($context);
        $infoSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $infoSlot)
        );
        HashTableHelper::setAtObjectKey(
            $context,
            $ht,
            $obj,
            new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $infoSlot)
        );
    }

    public static function attachEmptyStdClassLong(
        Context $context,
        JITVariable $htPtr,
        JITVariable $value
    ): void {
        $ht = self::htFromI64($context, $htPtr);
        $obj = self::allocEmptyStdClass($context);
        $longVal = JitLongArg::lower($context, $value, 'sos attach long info');
        $info = new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_LONG,
            JITVariable::KIND_VALUE,
            $longVal
        );
        HashTableHelper::setAtObjectKey($context, $ht, $obj, $info);
    }

    public static function attachEmptyStdClassString(
        Context $context,
        JITVariable $htPtr,
        JITVariable $str
    ): void {
        $ht = self::htFromI64($context, $htPtr);
        $obj = self::allocEmptyStdClass($context);
        $strPtr = self::loadStringArg($context, $str);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $strPtr
        );
        $info = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $owned
        );
        HashTableHelper::setAtObjectKey($context, $ht, $obj, $info);
    }

    private static function allocEmptyStdClass(Context $context): Value
    {
        /** @var ObjectBuiltin $object */
        $object = $context->type->object;
        $classId = $object->lookup('stdClass');
        BasicBlockHelper::ensureOpenInsertBlock($context, 'sos_attach_stdclass_alloc');
        $objVal = $object->allocate($classId);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'sos_attach_stdclass_after_alloc');
        $object->markObjectConstructed($objVal);
        // setObjectKey* stores the pointer without addref; NestedJIT temps delref on return
        // and would free the key while SOS still holds it — foreach SIGSEGV (#33876).
        $context->refcount->disableRefcount($objVal);

        return $objVal;
    }

    private static function htFromI64(Context $context, JITVariable $htPtr): Value
    {
        return \PHPCompiler\JIT\Builtin\ParseStrNativeOpsJit::htPointerFromI64Arg($context, $htPtr);
    }

    private static function loadStringArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__toString'),
            JitValueBox::valuePtrFromVariable($context, $arg)
        );
    }
}
