<?php

declare(strict_types=1);

namespace PHPCompiler\ext\tokenizer;

use PHPCompiler\JIT\Builtin\TokenGetAll;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
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
        // Arity is enforced in token_get_all::call via requireArgCountRangeJit (#30890).
        // Keep a defensive floor so direct lower() callers still match Zend at-least wording.
        if ($argc < 1) {
            throw new \ArgumentCountError('token_get_all() expects at least 1 argument, '.$argc.' given');
        }

        $sourceLit = $args[0]->compileTimeString ?? null;
        $flagsLit = self::compileTimeFlags($context, $args, $argc);
        // Non-empty compile-time source — materialize. Empty "" uses runtime empty HT
        // (nested TokenGetAllJitHelper returns garbage for "" on AOT; #21503).
        // TOKEN_PARSE ParseError must throw at runtime, not abort JIT/AOT compile (#26671).
        if (null !== $sourceLit && '' !== $sourceLit && null !== $flagsLit) {
            try {
                return self::materializeCompileTime($context, $sourceLit, $flagsLit);
            } catch (\ParseError $e) {
                // Fall through to runtime helper so the compiled unit throws ParseError.
            }
        }
        if ('' === $sourceLit && null !== $flagsLit) {
            return self::materializeEmptyTokens($context);
        }

        // Soft-null compile-time null → DEP + empty tokens when not strict (#21503).
        // Caller strict_types → TypeError via lowerTrimFamilyString (#30257; php-src Z_PARAM_STR).
        if (
            (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))
            && !$context->callerStrictTypes
        ) {
            JitStringBuiltinArg::emitNullStringParamDeprecation($context, 'token_get_all', 0, 'code');

            return self::materializeEmptyTokens($context);
        }
        if (
            $context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))
        ) {
            // Emit TypeError+abort then return a dummy slot — do not lower the helper after terminator (#30257).
            JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'token_get_all', 0, 'code');

            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        // php-src Z_PARAM_STR — soft-null DEP+coerce outside strict_types (#21503 / #30257).
        $source = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'token_get_all', 0, 'code');
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

    /** Empty token list without TokenGetAllJitHelper — AOT-safe (#21503). */
    public static function materializeEmptyTokens(Context $context): Value
    {
        $ht = HashTableHelper::alloc($context);
        $context->refcount->addref($ht);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $ht);

        return $ptr;
    }

    public static function materializeCompileTime(Context $context, string $source, int $flags): Value
    {
        if ('' === $source) {
            return self::materializeEmptyTokens($context);
        }
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
