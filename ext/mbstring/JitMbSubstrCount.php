<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbSubstrCountRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for mb_substr_count() — MbSubstrCountJitHelper in-module (#4637 AOT leftover).
 *
 * Runtime encoding via NestedJIT assertEncodingArgv (#35155 leftover of #4637 / peer #34884).
 */
final class JitMbSubstrCount
{
    /**
     * @param list<JITVariable> $args
     */
    public static function invoke(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('mb_substr_count() requires two or three arguments');
        }

        if (
            2 === $argc
            && JITVariable::TYPE_STRING === $args[0]->type
            && JITVariable::TYPE_STRING === $args[1]->type
            && null !== ($args[0]->compileTimeString ?? null)
            && null !== ($args[1]->compileTimeString ?? null)
            && '' !== $args[1]->compileTimeString
        ) {
            return $context->constantFromInteger(
                VmString::substr_count($args[0]->compileTimeString, $args[1]->compileTimeString),
                'int64'
            );
        }

        // Link NestedJIT helpers before lowering args — NestedJIT can invalidate prior IR (#34270 / #35155).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbSubstrCountRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_substr_count_runtime');

        $haystack = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[0],
            'mb_substr_count',
            0,
            'haystack'
        );
        $needle = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[1],
            'mb_substr_count',
            1,
            'needle'
        );
        [$encPtr, $needsAssert] = self::encodingPtr($context, $args, $argc);
        if ($needsAssert) {
            $fnName = $context->builder->load($context->constantStringFromString('mb_substr_count'));
            $context->builder->call(
                MbSubstrCountRuntime::assertEncodingHelper($context),
                $encPtr,
                $fnName
            );
        }

        return $context->builder->call(
            MbSubstrCountRuntime::substrCountHelper($context),
            $haystack,
            $needle,
            $encPtr
        );
    }

    /**
     * Literal UTF-8/ASCII/8BIT → constant string (no assert); otherwise NestedJIT encoding + assert (#35155).
     *
     * @param list<JITVariable> $args
     * @return array{0: Value, 1: bool} encoding ptr, needsAssert
     */
    private static function encodingPtr(Context $context, array $args, int $argc): array
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
                'mb_substr_count',
                2,
                'encoding'
            ),
            true,
        ];
    }

    private static function isSupportedEncoding(string $encoding): bool
    {
        return 'UTF-8' === $encoding || 'ASCII' === $encoding || '8BIT' === $encoding;
    }
}
