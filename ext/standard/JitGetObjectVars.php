<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\StringGetObjectVars;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT helper for get_object_vars() / get_mangled_object_vars() (#1370, #16629, #26797).
 *
 * Embed/MCJIT: runtime operands route through {@see GetObjectVarsJitHelper} PHP SSOT.
 * Standalone AOT: native class-id LLVM ({@see JitGetObjectVarsNative}) — NestedJIT helpers
 * cannot see user-class property metadata (#579 / empty helper unit).
 *
 * Compile-time enum-case operands keep registry fast path on both load types.
 * php-src: ext/standard/var.c — PHP_FUNCTION(get_object_vars)
 */
final class JitGetObjectVars
{
    private const TYPE_ERROR = '%s(): Argument #1 ($object) must be of type object, %s given';

    public static function invoke(Context $context, JITVariable $objectArg, bool $mangledKeys = false): Value
    {
        $function = $mangledKeys ? 'get_mangled_object_vars' : 'get_object_vars';
        $compileTimeEnum = $objectArg->compileTimeEnumCase ?? null;
        if (\is_array($compileTimeEnum) && isset($compileTimeEnum['classId'], $compileTimeEnum['caseKey'])) {
            $object = $context->type->object;
            if (!$object instanceof ObjectBuiltin) {
                throw new \LogicException('get_object_vars() requires object type metadata in this compiler build');
            }
            $caseObj = $object->jitEnumCaseFromBacking((int) $compileTimeEnum['classId'], (string) $compileTimeEnum['caseKey']);
            $objPtr = JITVariable::KIND_VALUE === $caseObj->kind
                ? $caseObj->value
                : $context->builder->load($caseObj->value);
            $ht = HashTableHelper::alloc($context);
            self::appendEnumCaseObjectVars($context, $object, $objPtr, (int) $compileTimeEnum['classId'], $ht);

            return self::boxedHashtable($context, $ht);
        }

        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return JitGetObjectVarsNative::invoke($context, $objectArg, $mangledKeys);
        }

        if (JITVariable::TYPE_OBJECT !== $objectArg->type && JITVariable::TYPE_VALUE !== $objectArg->type) {
            self::emitTypeErrorAndAbort(
                $context,
                self::formatTypeError($function, \PHPCompiler\JIT\JitOperandTypeLabel::givenLabel($context, $objectArg))
            );

            return self::boxedHashtable($context, HashTableHelper::alloc($context));
        }

        return self::routeThroughPhpHelper($context, $objectArg, $mangledKeys);
    }

    private static function routeThroughPhpHelper(
        Context $context,
        JITVariable $objectArg,
        bool $mangledKeys
    ): Value {
        $operandPtr = self::operandToValueBox($context, $objectArg);
        $i1 = $context->getTypeFromString('int1');
        $mangledVal = $mangledKeys ? $i1->constInt(1, false) : $i1->constInt(0, false);

        return StringGetObjectVars::invoke($context, $operandPtr, $mangledVal);
    }

    private static function operandToValueBox(Context $context, JITVariable $objectArg): Value
    {
        if (JITVariable::TYPE_VALUE === $objectArg->type) {
            return JitValueBox::valuePtrFromVariable($context, $objectArg);
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $context->helper->loadValue($objectArg)
        );

        return $ptr;
    }

    private static function boxedHashtable(Context $context, Value $ht): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }

    private static function appendEnumCaseObjectVars(
        Context $context,
        ObjectBuiltin $object,
        Value $objPtr,
        int $enumClassId,
        Value $ht
    ): void {
        $nameFetched = $object->fetchEnumCaseBuiltinProperty($objPtr, $enumClassId, 'name');
        $nameKey = $context->builder->load($context->constantStringFromString('name'));
        HashTableHelper::setAtStringKey($context, $ht, $nameKey, $nameFetched);
        if (!$object->enumHasBacking($enumClassId)) {
            return;
        }
        $valueFetched = $object->fetchEnumCaseBuiltinProperty($objPtr, $enumClassId, 'value');
        $valueKey = $context->builder->load($context->constantStringFromString('value'));
        HashTableHelper::setAtStringKey($context, $ht, $valueKey, $valueFetched);
    }

    private static function emitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function formatTypeError(string $function, string $given): string
    {
        return \sprintf(self::TYPE_ERROR, $function, $given);
    }
}
