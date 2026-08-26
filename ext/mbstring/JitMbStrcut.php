<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbStrcut;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for mb_strcut() — MbStrcutJitHelper in-module (#4573 / #34256 / #34875).
 *
 * Runtime int offsets must go through {@see JitNestedHelperCoerce::callHelper} (raw call SIGSEGVs).
 * Runtime encoding via NestedJIT assertEncodingArgv (#34875 leftover of #34256).
 */
final class JitMbStrcut
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('mb_strcut() expects 2 to 4 arguments in this compiler build');
        }

        $strLit = $args[0]->compileTimeString ?? null;
        $fromLit = self::compileTimeInt($context, $args[1]);
        $lenLit = $argc >= 3 ? self::compileTimeOptionalInt($context, $args[2]) : -1;
        $encLit = self::compileTimeEncoding($args, $argc);
        // Only fold when encoding is a supported canon — invalid names must reach NestedJIT
        // for catchable ValueError (peer JitMbSearch #34866; #34875).
        if (
            null !== $strLit
            && null !== $fromLit
            && null !== $lenLit
            && null !== $encLit
            && self::isSupportedEncoding($encLit)
        ) {
            return self::materializeString(
                $context,
                VmMbstring::strcut($strLit, $fromLit, $lenLit < 0 ? null : $lenLit, $encLit)
            );
        }

        // Soft-null DEP+coerce on 8.4 (php-src mbstring.c; #21430).
        $str = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'mb_strcut', 0, 'string');
        $from = JitStrictIntArg::lower($context, $args[1], 'mb_strcut', 2, 'start');
        $i64 = $context->getTypeFromString('int64');
        if ($argc >= 3) {
            if (JITVariable::TYPE_NULL === $args[2]->type) {
                $length = $i64->constInt(-1, true);
            } else {
                $length = JitStrictIntArg::lower($context, $args[2], 'mb_strcut', 3, 'length');
            }
        } else {
            $length = $i64->constInt(-1, true);
        }

        $encPtr = self::linkAndEncodingPtr($context, $args, $argc, 'mb_strcut');
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            MbStrcut::helperFunction($context),
            [$str, $from, $length, $encPtr]
        );
        $resultStr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $resultStr);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }

    /**
     * Link NestedJIT strcut helpers, lower encoding (literal or runtime), assert when needed (#34875).
     *
     * @param list<JITVariable> $args
     */
    private static function linkAndEncodingPtr(Context $context, array $args, int $argc, string $function): Value
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbStrcut::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, $function.'_runtime');

        [$encPtr, $needsAssert] = self::encodingPtr($context, $args, $argc, $function);
        if ($needsAssert) {
            $fnName = $context->builder->load($context->constantStringFromString($function));
            $context->builder->call(
                MbStrcut::assertEncodingHelper($context),
                $encPtr,
                $fnName
            );
        }

        return $encPtr;
    }

    /**
     * Literal UTF-8/ASCII/8BIT → constant string (no assert); otherwise NestedJIT encoding + assert (#34875).
     *
     * @param list<JITVariable> $args
     * @return array{0: Value, 1: bool} encoding ptr, needsAssert
     */
    private static function encodingPtr(Context $context, array $args, int $argc, string $function): array
    {
        if ($argc < 4 || JITVariable::TYPE_NULL === $args[3]->type || ($args[3]->isNullConstant ?? false)) {
            $encoding = MbstringAotFoldState::internalEncoding($context) ?? MbstringState::internalEncoding();
            if (!self::isSupportedEncoding($encoding)) {
                $encoding = 'UTF-8';
            }

            return [$context->builder->load($context->constantStringFromString($encoding)), false];
        }

        $encodingLit = JitStringArg::compileTimeLiteral($args[3]);
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
                $args[3],
                $function,
                3,
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
        if ($argc < 4) {
            return MbstringState::internalEncoding();
        }
        if (JITVariable::TYPE_NULL === $args[3]->type || ($args[3]->isNullConstant ?? false)) {
            return MbstringState::internalEncoding();
        }
        $lit = JitStringArg::compileTimeLiteral($args[3]);
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

    private static function materializeString(Context $context, string $str): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $context->builder->load($context->constantStringFromString($str))
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }

    private static function compileTimeInt(Context $context, JITVariable $arg): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG !== $arg->type || JITVariable::KIND_VALUE !== $arg->kind) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (null === $lib->LLVMIsAConstantInt($arg->value->value)) {
            return null;
        }

        return (int) $lib->LLVMConstIntGetSExtValue($arg->value->value);
    }

    private static function compileTimeOptionalInt(Context $context, JITVariable $arg): ?int
    {
        if (JITVariable::TYPE_NULL === $arg->type) {
            return -1;
        }

        return self::compileTimeInt($context, $arg);
    }
}
