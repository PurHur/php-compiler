<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbScrubRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TypeErrorRaise;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_scrub() (php-src ext/mbstring/mbstring.c; #6050, #34338, #35161).
 *
 * Compile-time fold for string literals; runtime string via NestedJIT
 * {@see MbScrubJitHelper}. Runtime encoding via NestedJIT assertEncodingArgv
 * (#35161 leftover of #34338 / peer #35155 / #35151).
 */
final class JitMbScrub
{
    /**
     * @param list<JITVariable> $args
     */
    public static function invoke(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('mb_scrub() requires one or two arguments');
        }

        $folded = self::tryCompileTimeFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        // Link NestedJIT helpers before lowering args — NestedJIT can invalidate prior IR (#34270 / #35161).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbScrubRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_scrub_runtime');

        // Soft-null DEP+coerce on 8.4 (php-src mbstring.c; #21516).
        $str = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[0],
            'mb_scrub',
            0,
            'string'
        );
        [$encPtr, $needsAssert] = self::encodingPtr($context, $args, $argc);
        if ($needsAssert) {
            $fnName = $context->builder->load($context->constantStringFromString('mb_scrub'));
            $context->builder->call(
                MbScrubRuntime::assertEncodingHelper($context),
                $encPtr,
                $fnName
            );
        }

        $resultStr = $context->builder->call(
            MbScrubRuntime::scrubHelper($context),
            $str,
            $encPtr
        );

        return self::materializeOwnedString($context, $resultStr);
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryCompileTimeFold(Context $context, array $args): ?Value
    {
        if (!isset($args[0])) {
            return null;
        }
        // Soft-null DEP+coerce on 8.4 (php-src mbstring.c; #21516, reverts #21061 TypeError).
        if (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant) {
            if ($context->callerStrictTypes) {
                return JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'mb_scrub', 0, 'string');
            }
            $string = '';
        } else {
            $string = JitStringArg::compileTimeLiteral($args[0]);
            if (null === $string) {
                return null;
            }
        }
        $encoding = self::compileTimeEncoding($args, 1);
        if (null === $encoding) {
            return null;
        }
        if (null === self::canonicalEncoding($encoding)) {
            return self::emitEncodingValueError($context, $encoding);
        }

        return self::materializeString($context, VmMbstring::scrub($string, $encoding));
    }

    /**
     * Literal UTF-8/ASCII/8BIT → constant string (no assert); otherwise NestedJIT encoding + assert (#35161).
     *
     * @param list<JITVariable> $args
     * @return array{0: Value, 1: bool} encoding ptr, needsAssert
     */
    private static function encodingPtr(Context $context, array $args, int $argc): array
    {
        if ($argc < 2 || JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
            return [$context->builder->load($context->constantStringFromString('UTF-8')), false];
        }

        $encodingLit = JitStringArg::compileTimeLiteral($args[1]);
        if (null !== $encodingLit) {
            $canonical = self::canonicalEncoding($encodingLit);
            if (null !== $canonical) {
                return [$context->builder->load($context->constantStringFromString($canonical)), false];
            }

            // Invalid / unsupported literal — NestedJIT assert throws catchable ValueError (#35161).
            return [$context->builder->load($context->constantStringFromString($encodingLit)), true];
        }

        return [
            JitStringBuiltinArg::lower(
                $context,
                $args[1],
                'mb_scrub',
                1,
                'encoding'
            ),
            true,
        ];
    }

    /**
     * @param JITVariable[] $args
     */
    private static function compileTimeEncoding(array $args, int $index): ?string
    {
        if (!isset($args[$index])) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_NULL === $args[$index]->type || ($args[$index]->isNullConstant ?? false)) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_STRING !== $args[$index]->type) {
            return null;
        }

        return $args[$index]->compileTimeString ?? null;
    }

    private static function canonicalEncoding(string $encoding): ?string
    {
        $upper = strtoupper($encoding);
        if ('UTF-8' === $upper || 'UTF8' === $upper) {
            return 'UTF-8';
        }
        if ('ASCII' === $upper || 'US-ASCII' === $upper) {
            return 'ASCII';
        }
        if ('8BIT' === $upper || 'BINARY' === $upper) {
            return '8BIT';
        }

        return null;
    }

    private static function emitEncodingValueError(Context $context, string $encoding): Value
    {
        TypeErrorRaise::emitValueError(
            $context,
            sprintf(
                'mb_scrub(): Argument #2 ($encoding) must be a valid encoding, "%s" given',
                $encoding
            )
        );
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_scrub_bad_enc_dead');

        return JitValueBox::pointer($context, JitValueBox::alloc($context));
    }

    private static function materializeOwnedString(Context $context, Value $resultStr): Value
    {
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $resultStr);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }

    private static function materializeString(Context $context, string $str): Value
    {
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $context->builder->load($context->constantStringFromString($str))
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }
}
