<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\HashAlgosRegistry;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\UserScriptAotDeferNestedJit;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_hash_hmac_algos via HashAlgosJitHelper PHP (#18908).
 *
 * User-script standalone AOT uses inline registry LLVM (same as {@see StringHashAlgos})
 * because nested HashAlgosJitHelper emits invalid __hashtable__ bridge types (#3357).
 * SSOT: {@see \PHPCompiler\ext\standard\VmHash::hmacAlgos()}
 * php-src: ext/hash/hash.c — php_hash_hmac_algos()
 */
final class StringHashHmacAlgos
{
    private const ABI_HASH_HMAC_ALGOS = '__compiler_hash_hmac_algos';

    private const HELPER_PATH = '/ext/hash/HashAlgosJitHelper.php';

    private const HMAC_ALGOS_HELPER = 'PHPCompiler\\ext\\hash\\HashAlgosJitHelper::hmacAlgosArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HMAC_ALGOS_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_HASH_HMAC_ALGOS);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_HASH_HMAC_ALGOS, $probe);

            return;
        }

        if (UserScriptAotDeferNestedJit::shouldDefer($context)) {
            self::implementInlineRegistry($context, $probe);

            return;
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#18908');
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::HMAC_ALGOS_HELPER, '#18908');

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_HASH_HMAC_ALGOS,
                $context->context->functionType($htPtr, false)
            );
        self::implementBridge($context, $fn, $helperFn);
        $context->registerFunction(self::ABI_HASH_HMAC_ALGOS, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementBridge(Context $context, LlvmFunction $fn, LlvmFunction $helperFn): void
    {
        $entry = $fn->appendBasicBlock('hash_hmac_algos_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $htRaw = $context->builder->call($helperFn);
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
        $context->builder->returnValue($ht);
    }

    private static function implementInlineRegistry(Context $context, ?LlvmFunction $probe): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_HASH_HMAC_ALGOS,
                $context->context->functionType($htPtr, false)
            );

        $entry = $fn->appendBasicBlock('hash_hmac_algos_inline_entry');
        $context->builder->positionAtEnd($entry);

        $i64 = $context->getTypeFromString('int64');
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $nullHt = $htPtr->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $ht, $nullHt);

        $failBb = $fn->appendBasicBlock('hash_hmac_algos_inline_fail');
        $buildBb = $fn->appendBasicBlock('hash_hmac_algos_inline_build');
        $context->builder->branchIf($isNull, $failBb, $buildBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullHt);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($buildBb);
        $setAt = $context->lookupFunction('__hashtable__setStringAt');
        foreach (HashAlgosRegistry::HMAC_ALGOS as $index => $algo) {
            $context->builder->call(
                $setAt,
                $ht,
                $i64->constInt($index, false),
                self::literalString($context, $algo)
            );
        }
        $context->builder->returnValue($ht);
        $context->builder->clearInsertionPosition();

        $context->registerFunction(self::ABI_HASH_HMAC_ALGOS, $fn);
    }

    private static function literalString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $cstr = $context->builder->pointerCast($context->constantFromString($text), $charPtr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $cstr
        );
    }
}
