<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitConstant;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DefineRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * ReflectionConstant::getValue() — JIT/AOT (#27303).
 *
 * Prefer the construct-time cached box (`value` prop). Fall back to constant()
 * via {@see JitConstant} when the cache is still null (dynamic name).
 */
final class ReflectionConstantGetValue implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // php-src: zim_ReflectionConstant_getValue — 0 user args (#30896)
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            \PHPCompiler\JIT\ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf(
                    'ReflectionConstant::getValue() expects exactly 0 arguments, %d given',
                    $userArgCount
                )
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'refl_const_getvalue_argc_cont');

            return JitValueBox::alloc($context);
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $cached = $context->type->object->propertyFetch($obj, 'ReflectionConstant', 'value');
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $cached);
        $typeMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load($context->builder->structGep($valuePtr, $typeMap['type']));
        $i8 = $context->getTypeFromString('int8');
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NULL, false)
        );

        $fn = BasicBlockHelper::parentFunction($context);
        $cachedBlock = $fn->appendBasicBlock('rc_getvalue_cached');
        $lookupBlock = $fn->appendBasicBlock('rc_getvalue_lookup');
        $doneBlock = $fn->appendBasicBlock('rc_getvalue_done');
        $resultTy = $context->getTypeFromString('__value__*');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $resultTy);
        $context->builder->branchIf($isNull, $lookupBlock, $cachedBlock);

        $context->builder->positionAtEnd($cachedBlock);
        $context->builder->store($valuePtr, $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($lookupBlock);
        DefineRuntime::ensureLinked($context);
        [$cstr, $len] = ReflectionSetup::stringPropertyAsCstr(
            $context,
            $obj,
            'ReflectionConstant',
            ReflectionSupport::PROP_CONSTANT_NAME
        );
        $i64 = $context->getTypeFromString('int64');
        $nameStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $cstr
        );
        $nameJit = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $nameStr);
        $looked = JitConstant::invoke($context, $nameJit);
        $context->builder->store($looked, $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $context->builder->load($resultSlot);
    }
}
