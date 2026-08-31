<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionInternalFunctionLowering;
use PHPCompiler\JIT\Builtin\ReflectionNative;
use PHPCompiler\JIT\Builtin\ReflectionParameterJitHelper;
use PHPCompiler\JIT\Builtin\ReflectionRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** ReflectionFunction::getParameters() — JIT/AOT (#28780, ext/reflection/php_reflection.c). */
final class ReflectionFunctionGetParameters implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'ReflectionFunction::getParameters()',
            0
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);
        ReflectionInternalFunctionLowering::noteRuntimeInternalParameterLookup();
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$funcCstr] = ReflectionSetup::stringPropertyAsCstr(
            $context,
            $obj,
            'ReflectionFunction',
            ReflectionSupport::PROP_REFLECTION_FUNCTION_NAME
        );
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $count = $context->builder->call(
            $context->lookupFunction('__compiler_refl_func_param_count'),
            $funcCstr
        );
        $ht = HashTableHelper::alloc($context);
        $need = $context->builder->select(
            $context->builder->icmp(Builder::INT_UGT, $count, $sizeT->constInt(0, false)),
            $count,
            $sizeT->constInt(1, false)
        );
        $context->builder->call($context->lookupFunction('__hashtable__grow'), $ht, $need);

        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $iSlot);
        $done = BasicBlockHelper::append($context, 'refl_func_params_done');
        $head = BasicBlockHelper::append($context, 'refl_func_params_head');
        $body = BasicBlockHelper::append($context, 'refl_func_params_body');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $inRange = $context->builder->icmp(Builder::INT_ULT, $i, $count);
        $context->builder->branchIf($inRange, $body, $done);

        $context->builder->positionAtEnd($body);
        $indexI64 = $context->builder->zExt($i, $i64);
        $paramObj = ReflectionParameterJitHelper::emitInternalParamObjectFromLookup(
            $context,
            $funcCstr,
            $indexI64
        );
        HashTableHelper::setAtIndex(
            $context,
            $ht,
            $i,
            new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $paramObj)
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap($i, $sizeT->constInt(1, false)),
            $iSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $ht;
    }
}
