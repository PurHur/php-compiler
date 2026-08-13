<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT compile-time folding for mb_list_encodings() / mb_encoding_aliases().
 *
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
        if (null === $encodingLit) {
            throw new \LogicException(
                'mb_encoding_aliases() encoding must be a compile-time string in this compiler build'
            );
        }
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
