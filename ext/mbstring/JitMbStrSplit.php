<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\JitExplode;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbStrSplitRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for mb_str_split() (#26870 / #34278 / #34880).
 *
 * Compile-time fold: {@see VmMbstring::strSplit} → packed HT.
 * Runtime: NestedJIT string peel (no HashTable under thin AOT — peer explode #27660)
 * then {@see JitExplode} in the user module.
 * Runtime encoding via NestedJIT assertEncodingArgv (#34880 leftover of #34278).
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_str_split)
 */
final class JitMbStrSplit
{
    private const LENGTH_ERROR = 'mb_str_split(): Argument #2 ($length) must be greater than 0';

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        // Arity checked by mb_str_split::call via requireArgCountRangeJit (#30786).
        $argc = \count($args);

        $stringLit = $args[0]->compileTimeString ?? null;
        $lengthLit = 1;
        $lengthIsLiteral = true;
        if ($argc >= 2) {
            $resolved = self::compileTimeLong($context, $args[1]);
            if (null === $resolved) {
                $lengthIsLiteral = false;
            } else {
                $lengthLit = $resolved;
            }
        }
        $encLit = self::compileTimeEncoding($args, $argc);
        // Only fold when encoding is a supported canon — invalid names must reach NestedJIT
        // for catchable ValueError (peer JitMbStrcut #34875; #34880).
        if (
            null !== $stringLit
            && $lengthIsLiteral
            && $lengthLit > 0
            && null !== $encLit
            && self::isSupportedEncoding($encLit)
        ) {
            return self::foldLiteral($context, $stringLit, $lengthLit, $encLit);
        }

        // Soft-null DEP+coerce on 8.4 (peer mb_strcut / #24207).
        $str = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[0],
            'mb_str_split',
            0,
            'string'
        );
        $i64 = $context->getTypeFromString('int64');
        $length = $i64->constInt(1, false);
        if ($argc >= 2) {
            $length = JitStrictIntArg::lower($context, $args[1], 'mb_str_split', 2, 'length');
        }
        self::emitLengthGuard($context, $length);

        $encPtr = self::linkAndEncodingPtr($context, $args, $argc, 'mb_str_split');
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            MbStrSplitRuntime::helperFunction($context),
            [$str, $length, $encPtr]
        );
        $joined = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);

        return self::hashtableFromJoined($context, $joined);
    }

    /**
     * Link NestedJIT str_split helpers, lower encoding (literal or runtime), assert when needed (#34880).
     *
     * @param list<JITVariable> $args
     */
    private static function linkAndEncodingPtr(Context $context, array $args, int $argc, string $function): Value
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbStrSplitRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, $function.'_runtime');

        [$encPtr, $needsAssert] = self::encodingPtr($context, $args, $argc, $function);
        if ($needsAssert) {
            $fnName = $context->builder->load($context->constantStringFromString($function));
            $context->builder->call(
                MbStrSplitRuntime::assertEncodingHelper($context),
                $encPtr,
                $fnName
            );
        }

        return $encPtr;
    }

    /**
     * Literal UTF-8/ASCII/8BIT → constant string (no assert); otherwise NestedJIT encoding + assert (#34880).
     *
     * @param list<JITVariable> $args
     * @return array{0: Value, 1: bool} encoding ptr, needsAssert
     */
    private static function encodingPtr(Context $context, array $args, int $argc, string $function): array
    {
        if ($argc < 3 || JITVariable::TYPE_NULL === $args[2]->type || ($args[2]->isNullConstant ?? false)) {
            $encoding = MbstringAotFoldState::internalEncoding($context) ?? MbstringState::internalEncoding();
            if (!self::isSupportedEncoding($encoding)) {
                $encoding = 'UTF-8';
            }

            return [$context->builder->load($context->constantStringFromString($encoding)), false];
        }

        $encodingLit = JitStringArg::compileTimeLiteral($args[2]);
        if (null !== $encodingLit) {
            $canonical = MbstringEncodingRegistry::resolve($encodingLit);
            if (null !== $canonical && self::isSupportedEncoding($canonical)) {
                return [$context->builder->load($context->constantStringFromString($canonical)), false];
            }

            return [$context->builder->load($context->constantStringFromString($encodingLit)), true];
        }

        return [
            JitStringBuiltinArg::lower(
                $context,
                $args[2],
                $function,
                2,
                'encoding'
            ),
            true,
        ];
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function compileTimeEncoding(array $args, int $argc): ?string
    {
        if ($argc < 3) {
            return MbstringState::internalEncoding();
        }
        if (JITVariable::TYPE_NULL === $args[2]->type || ($args[2]->isNullConstant ?? false)) {
            return MbstringState::internalEncoding();
        }
        $lit = JitStringArg::compileTimeLiteral($args[2]);
        if (null === $lit) {
            return null;
        }
        $canonical = MbstringEncodingRegistry::resolve($lit);

        return null !== $canonical ? $canonical : $lit;
    }

    private static function isSupportedEncoding(string $encoding): bool
    {
        return 'UTF-8' === $encoding || 'ASCII' === $encoding || '8BIT' === $encoding;
    }

    /** php-src ext/mbstring/mbstring.c — length must be > 0 before split. */
    private static function emitLengthGuard(Context $context, Value $length): void
    {
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $valid = $context->builder->icmp(Builder::INT_SGE, $length, $one);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitBranchOrAbortOnValueErrorFailure(
            $context,
            $valid,
            'mb_str_split_len',
            self::LENGTH_ERROR
        );
    }

    /** Empty joined → []; otherwise explode on {@see MbStrSplitJitHelper::JOIN_DELIM}. */
    private static function hashtableFromJoined(Context $context, Value $joined): Value
    {
        $tag = 'mbss';
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $slen = $context->builder->call($context->lookupFunction('__string__strlen'), $joined);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $slen, $zero);

        $emptyBlock = BasicBlockHelper::append($context, 'mb_str_split_empty_'.$tag);
        $explodeBlock = BasicBlockHelper::append($context, 'mb_str_split_explode_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'mb_str_split_done_'.$tag);
        $context->builder->branchIf($isEmpty, $emptyBlock, $explodeBlock);

        $htTy = $context->getTypeFromString('__hashtable__*');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $htTy);

        $context->builder->positionAtEnd($emptyBlock);
        $context->builder->store(HashTableHelper::alloc($context), $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($explodeBlock);
        $delim = $context->builder->load(
            $context->constantStringFromString(MbStrSplitJitHelper::JOIN_DELIM)
        );
        $ownedJoined = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $joined
        );
        $ht = JitExplode::explode($context, $delim, $ownedJoined);
        $context->builder->store($ht, $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $context->builder->load($resultSlot);
    }

    private static function foldLiteral(
        Context $context,
        string $literal,
        int $length,
        string $encoding
    ): Value {
        $parts = VmMbstring::strSplit($literal, $length, $encoding);

        return self::buildHtFromStringParts($context, $parts);
    }

    /** @param list<string> $parts */
    private static function buildHtFromStringParts(Context $context, array $parts): Value
    {
        $ht = HashTableHelper::alloc($context);
        $setString = $context->lookupFunction('__hashtable__setStringAt');
        $sizeT = $context->getTypeFromString('size_t');
        foreach ($parts as $i => $part) {
            $slice = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $context->builder->load($context->constantStringFromString($part))
            );
            $context->builder->call(
                $setString,
                $ht,
                $sizeT->constInt($i, false),
                $slice
            );
        }

        return $ht;
    }

    private static function compileTimeLong(Context $context, JITVariable $var): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG === $var->type && JITVariable::KIND_VALUE === $var->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
                return (int) $lib->LLVMConstIntGetSExtValue($var->value->value);
            }
        }

        return null;
    }
}
