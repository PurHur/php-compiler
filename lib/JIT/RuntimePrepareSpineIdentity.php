<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * Identity LLVM stubs for Runtime prepare/preprocess/rewrite under M5 argv (#26756 / #11809).
 *
 * Void stubs registered from {main}'s Block make host-lowered Runtime::parse treat
 * prepareSourceForParser as void — $code stays null and parse SEGV. These stubs keep the
 * real signatures and return [$code, []] / $code so hello-world gen-0 smoke can proceed
 * without NestedJIT of the full rewriter chain.
 */
final class RuntimePrepareSpineIdentity
{
    /**
     * @param callable(string):string $llvmInternalName
     * @param callable(string, Value, list<\PHPLLVM\Type>, list<mixed>):void $registerProxy
     */
    public static function ensure(
        Context $context,
        callable $llvmInternalName,
        callable $registerProxy
    ): void {
        self::emitRewriteIdentity($context, $llvmInternalName, $registerProxy);
        self::emitArrayPairIdentity(
            $context,
            $llvmInternalName,
            $registerProxy,
            'PHPCompiler\\Runtime::preprocessSourceForParse'
        );
        self::emitArrayPairIdentity(
            $context,
            $llvmInternalName,
            $registerProxy,
            'PHPCompiler\\Runtime::prepareSourceForParser'
        );
    }

    /**
     * @param callable(string):string $llvmInternalName
     * @param callable(string, Value, list<\PHPLLVM\Type>, list<mixed>):void $registerProxy
     */
    private static function emitRewriteIdentity(
        Context $context,
        callable $llvmInternalName,
        callable $registerProxy
    ): void {
        $logical = 'PHPCompiler\\Runtime::rewriteSourceBeforeParser';
        $lc = strtolower($logical);
        if (isset($context->functions[$lc])) {
            return;
        }
        $objectPtr = $context->getTypeFromString('__object__*');
        $stringPtr = $context->getTypeFromString('__string__*');
        $func = $context->module->addFunction(
            $llvmInternalName($logical),
            $context->context->functionType($stringPtr, false, $objectPtr, $stringPtr, $stringPtr)
        );
        $bb = $func->appendBasicBlock('m5_rewrite_identity');
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();
        $context->builder->positionAtEnd($bb);
        // Param 1 = $code (param 0 = $this).
        $context->builder->returnValue($func->getParam(1));
        $context->builder->clearInsertionPosition();
        $context->builder = $saved;
        $context->functions[$lc] = $func;
        $context->functionReturnType[$lc] = '__string__*';
        $registerProxy($logical, $func, [$objectPtr, $stringPtr, $stringPtr], []);
    }

    /**
     * @param callable(string):string $llvmInternalName
     * @param callable(string, Value, list<\PHPLLVM\Type>, list<mixed>):void $registerProxy
     */
    private static function emitArrayPairIdentity(
        Context $context,
        callable $llvmInternalName,
        callable $registerProxy,
        string $logical
    ): void {
        $lc = strtolower($logical);
        if (isset($context->functions[$lc])) {
            return;
        }
        $objectPtr = $context->getTypeFromString('__object__*');
        $stringPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $sizeT = $context->getTypeFromString('size_t');
        $func = $context->module->addFunction(
            $llvmInternalName($logical),
            $context->context->functionType($htPtr, false, $objectPtr, $stringPtr, $stringPtr)
        );
        $bb = $func->appendBasicBlock('m5_prepare_identity');
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();
        $context->builder->positionAtEnd($bb);

        $outer = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $inner = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $code = $func->getParam(1);
        // Do not __string__separate here: under host-lowered Runtime::parse the $code param can
        // be a non-null garbage pointer after NestedJIT ABI skew, and separate→alloc SEGVs
        // (#26756). Store the pointer as-is; M5 parse skips this path via m5ArgvIdentityParsePrepare.
        $nullStr = $stringPtr->constNull();
        $isNull = $context->builder->icmp(\PHPLLVM\Builder::INT_EQ, $code, $nullStr);
        $empty = $context->builder->call(
            $context->lookupFunction('__string__alloc'),
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $toStore = $context->builder->select($isNull, $empty, $code);
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $outer,
            $zero,
            $toStore
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setHashtableAt'),
            $outer,
            $one,
            $inner
        );
        $context->builder->returnValue($outer);

        $context->builder->clearInsertionPosition();
        $context->builder = $saved;
        $context->functions[$lc] = $func;
        $context->functionReturnType[$lc] = '__hashtable__*';
        $registerProxy($logical, $func, [$objectPtr, $stringPtr, $stringPtr], []);
    }
}
