<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\JitExplode;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\MbEncodingAliasesRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_list_encodings() / mb_encoding_aliases().
 *
 * Compile-time fold + NestedJIT runtime encoding for aliases (#35216 leftover of #30795).
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_list_encodings) / mb_encoding_aliases
 * Packed HT via {@see HashTableHelper} (peer {@see JitMbStrSplit} — AOT json_encode-safe);
 * invalid encoding → catchable ValueError (peer php_uname #28136 / #30795).
 */
final class JitMbEncodingRegistry
{
    public static function foldListEncodings(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (0 !== $argc) {
            throw new \ArgumentCountError(sprintf(
                'mb_list_encodings() expects exactly 0 arguments, %d given',
                $argc
            ));
        }

        return self::buildHtFromStringParts($context, MbstringEncodingRegistry::listEncodings());
    }

    public static function foldEncodingAliases(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            throw new \ArgumentCountError(sprintf(
                'mb_encoding_aliases() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant) {
            return self::emitEncodingValueError(
                $context,
                'mb_encoding_aliases(): Argument #1 ($encoding) must be a valid encoding, "" given'
            );
        }
        $encodingLit = JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $encodingLit) {
            $canonical = MbstringEncodingRegistry::resolve($encodingLit);
            if (null === $canonical) {
                return self::emitEncodingValueError(
                    $context,
                    sprintf(
                        'mb_encoding_aliases(): Argument #1 ($encoding) must be a valid encoding, "%s" given',
                        $encodingLit
                    )
                );
            }
            // Transfer-encoding E_DEPRECATED is VM/runtime (#28983); AOT fold still returns aliases.

            return self::buildHtFromStringParts($context, MbstringEncodingRegistry::aliases($canonical));
        }

        // Runtime encoding (TYPE_VALUE / non-literal TYPE_STRING) — NestedJIT (#35216).
        return self::lowerRuntimeAliases($context, $args[0]);
    }

    /** NestedJIT joined aliases → packed HT (peer {@see JitMbStrSplit::hashtableFromJoined}). */
    private static function lowerRuntimeAliases(Context $context, JITVariable $encodingArg): Value
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbEncodingAliasesRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_encoding_aliases_runtime');

        $enc = JitStringBuiltinArg::lower(
            $context,
            $encodingArg,
            'mb_encoding_aliases',
            0,
            'encoding'
        );
        $context->builder->call(MbEncodingAliasesRuntime::assertEncodingHelper($context), $enc);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            MbEncodingAliasesRuntime::aliasesHelper($context),
            [$enc]
        );
        $joined = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);

        return self::hashtableFromJoined($context, $joined);
    }

    /** Empty joined → []; otherwise explode on {@see MbEncodingAliasesJitHelper::JOIN_DELIM}. */
    private static function hashtableFromJoined(Context $context, Value $joined): Value
    {
        $tag = 'mba';
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $slen = $context->builder->call($context->lookupFunction('__string__strlen'), $joined);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $slen, $zero);

        $emptyBlock = BasicBlockHelper::append($context, 'mb_encoding_aliases_empty_'.$tag);
        $explodeBlock = BasicBlockHelper::append($context, 'mb_encoding_aliases_explode_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'mb_encoding_aliases_done_'.$tag);
        $context->builder->branchIf($isEmpty, $emptyBlock, $explodeBlock);

        $htTy = $context->getTypeFromString('__hashtable__*');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $htTy);

        $context->builder->positionAtEnd($emptyBlock);
        $context->builder->store(HashTableHelper::alloc($context), $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($explodeBlock);
        $delim = $context->builder->load(
            $context->constantStringFromString(MbEncodingAliasesJitHelper::JOIN_DELIM)
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

    /** Public wrapper for mb_preferred_mime_name() compile-time ValueError (#34298). */
    public static function emitPreferredMimeValueError(Context $context, string $message): Value
    {
        return self::emitEncodingValueError($context, $message);
    }

    /** Catchable ValueError for compile-time-invalid $encoding (peer php_uname #28136). */
    private static function emitEncodingValueError(Context $context, string $message): Value
    {
        ExceptionBridge::ensureLinked($context);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            TypeErrorRaise::ensureStandaloneBodies($context);
        }
        if (null !== TryCatchHelper::resolveThrowHandler($context)) {
            TryCatchHelper::emitCatchableClassError($context, 'ValueError', $message);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_encoding_aliases_valueerror_dead');
        } else {
            TypeErrorRaise::emitValueError($context, $message);
            if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
                $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_type_error'));
            } else {
                $context->builder->call($context->lookupFunction('abort'));
                $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
            }
            BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_encoding_aliases_valueerror_dead');
        }

        return HashTableHelper::alloc($context);
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
}
