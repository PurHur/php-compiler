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
 * LLVM JIT/AOT for mb_scrub() (php-src ext/mbstring/mbstring.c; #6050, #34338).
 *
 * Compile-time fold for string literals; runtime string + encoding literal via NestedJIT
 * {@see MbScrubJitHelper} (peer {@see JitMbCase} / {@see JitMbConvertEncoding}).
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

        $encoding = self::runtimeEncodingLiteral($args, $argc);
        if (null === $encoding) {
            throw new \LogicException(
                'mb_scrub() encoding must be a string literal in this compiler build'
            );
        }
        if (null === self::canonicalEncoding($encoding)) {
            return self::emitEncodingValueError($context, $encoding);
        }

        // Soft-null DEP+coerce on 8.4 (php-src mbstring.c; #21516).
        $str = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[0],
            'mb_scrub',
            0,
            'string'
        );

        // NestedJIT helper compile can clear insert; restore before call (#34270 peer).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbScrubRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_scrub_runtime');

        $encPtr = $context->builder->load($context->constantStringFromString($encoding));
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

    /**
     * @param list<JITVariable> $args
     */
    private static function runtimeEncodingLiteral(array $args, int $argc): ?string
    {
        if ($argc < 2) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
            return 'UTF-8';
        }

        return JitStringArg::compileTimeLiteral($args[1]);
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
