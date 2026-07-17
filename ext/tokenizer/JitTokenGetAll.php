<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tokenizer;

use PHPCompiler\JIT\Builtin\TokenGetAll;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for token_get_all() (#3171, #4561, #6940). */
final class JitTokenGetAll
{
    public static function lower(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1) {
            throw new \ArgumentCountError('token_get_all() expects at least 1 argument, '.$argc.' given');
        }

        $sourceLit = $args[0]->compileTimeString ?? null;
        $flagsLit = self::compileTimeFlags($context, $args, $argc);
        if (null !== $sourceLit && null !== $flagsLit) {
            return self::materializeCompileTime($context, $sourceLit, $flagsLit);
        }

        // php-src Z_PARAM_STR — null TypeError on 8.4 forward profile (#19894).
        $source = JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'token_get_all', 0, 'source');
        if (
            (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))
            && (
                $context->callerStrictTypes
                || JitStringBuiltinArg::requiresZparamStrStrictNullOnForwardProfile()
            )
        ) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $flags = self::lowerFlagsRuntime($context, $args, $argc);
        $ht = $context->builder->call(
            TokenGetAll::helperFunction($context),
            $source,
            $flags
        );
        $context->refcount->addref($ht);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $ht);

        return $ptr;
    }

    public static function materializeCompileTime(Context $context, string $source, int $flags): Value
    {
        $ht = VmTokenizer::hostTokensToHashTable(VmTokenizer::tokenize($source, $flags));
        $cacheKey = 'token_get_all:'.md5($source).':'.$flags;
        $global = $context->constantArrayFromVmHashTable($cacheKey, $ht);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::copyFromPointer($context, $slot, $context->builder->load($global));

        return $ptr;
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function compileTimeFlags(Context $context, array $args, int $argc): ?int
    {
        if ($argc < 2) {
            return 0;
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type || JITVariable::KIND_VALUE !== $args[1]->kind) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (null === $lib->LLVMIsAConstantInt($args[1]->value->value)) {
            return null;
        }

        return (int) $lib->LLVMConstIntGetZExtValue($args[1]->value->value);
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function lowerFlagsRuntime(Context $context, array $args, int $argc): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if ($argc < 2) {
            return $i64->constInt(0, false);
        }

        return JitLongArg::lower($context, $args[1], 'token_get_all() flags argument');
    }
}
