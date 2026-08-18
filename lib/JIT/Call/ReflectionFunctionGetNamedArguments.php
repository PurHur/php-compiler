<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionNative;
use PHPCompiler\JIT\Builtin\ReflectionRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** ReflectionFunction::getNamedArguments() — JIT/AOT (#17658, ext/reflection/php_reflection.c). */
final class ReflectionFunctionGetNamedArguments implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$funcCstr] = ReflectionSetup::stringPropertyAsCstr(
            $context,
            $obj,
            'ReflectionFunction',
            ReflectionSupport::PROP_REFLECTION_FUNCTION_NAME
        );
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $count = $context->builder->call(
            $context->lookupFunction('__compiler_refl_func_named_count'),
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
        $done = BasicBlockHelper::append($context, 'refl_func_named_done');
        $head = BasicBlockHelper::append($context, 'refl_func_named_head');
        $body = BasicBlockHelper::append($context, 'refl_func_named_body');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $inRange = $context->builder->icmp(Builder::INT_ULT, $i, $count);
        $context->builder->branchIf($inRange, $body, $done);

        $context->builder->positionAtEnd($body);
        $namePtr = $context->builder->call(
            $context->lookupFunction('__compiler_refl_func_named_at'),
            $funcCstr,
            $i
        );
        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
        $nameLen = $context->builder->call(
            $context->lookupFunction('strlen'),
            $context->builder->pointerCast($namePtr, $i8p)
        );
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->sext($nameLen, $context->getTypeFromString('int64')),
            $context->builder->pointerCast($namePtr, $i8p)
        );
        HashTableHelper::setAtIndex(
            $context,
            $ht,
            $i,
            new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $str)
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
