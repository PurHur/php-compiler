<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbTrimRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helpers for mb_trim() / mb_ltrim() / mb_rtrim()
 * (php-src ext/mbstring/mbstring.c; #5957, #9208, #23883, #34379, #35199).
 *
 * Compile-time fold for string literals; runtime haystack via NestedJIT
 * {@see MbTrimJitHelper} (peer {@see JitMbScrub}). Runtime encoding via
 * NestedJIT assertEncodingArgv (#35199 leftover of #34379 / peer #35161).
 * $characters stays a compile-time string or null.
 */
final class JitMbTrim
{
    /**
     * @param JITVariable[] $args
     */
    public static function invoke(Context $context, int $mode, string $function, array $args): Value
    {
        $folded = self::tryCompileTimeFold($context, $mode, $function, $args);
        if (null !== $folded) {
            return $folded;
        }

        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException(
                $function.'() expects 1 to 3 arguments in this compiler build'
            );
        }

        $what = self::compileTimeWhat($args, 1);
        if (false === $what) {
            throw new \LogicException(
                $function.'() characters must be a compile-time string or null in this compiler build'
            );
        }

        // Soft-null DEP+coerce on 8.4 (php-src mbstring.c; peer mb_scrub #21516).
        $str = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[0],
            $function,
            0,
            'string'
        );

        // Empty $characters → no trim (Zend). Decide at compile time — NestedJIT
        // isset-length on helper string params is unreliable (#34379).
        if (null !== $what && '' === $what) {
            return self::materializeOwnedString($context, $str);
        }

        // Link NestedJIT helpers before encoding lower — NestedJIT can invalidate prior IR (#34270 / #35199).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbTrimRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, $function.'_runtime');

        if (null === $what) {
            [$encPtr, $needsAssert] = self::encodingPtr($context, $args, $argc, $function);
            if ($needsAssert) {
                $fnName = $context->builder->load($context->constantStringFromString($function));
                $context->builder->call(
                    MbTrimRuntime::assertEncodingHelper($context),
                    $encPtr,
                    $fnName
                );
            }
            $helper = match ($mode) {
                1 => MbTrimRuntime::ltrimDefaultHelper($context),
                2 => MbTrimRuntime::rtrimDefaultHelper($context),
                default => MbTrimRuntime::trimDefaultHelper($context),
            };
            // Two-string ABI like mb_scrub — raw call; callHelper/`__value__` 1-arg SIGSEGVs.
            $resultStr = $context->builder->call($helper, $str, $encPtr);
        } else {
            if (3 !== $mode) {
                throw new \LogicException(
                    $function.'() with custom $characters only supports trim (both sides) in this compiler build'
                );
            }
            // Custom characters path still validates encoding when passed (#35199).
            if ($argc >= 3) {
                [$encPtr, $needsAssert] = self::encodingPtr($context, $args, $argc, $function);
                if ($needsAssert) {
                    $fnName = $context->builder->load($context->constantStringFromString($function));
                    $context->builder->call(
                        MbTrimRuntime::assertEncodingHelper($context),
                        $encPtr,
                        $fnName
                    );
                }
            }
            $whatPtr = $context->builder->load($context->constantStringFromString($what));
            $resultStr = $context->builder->call(
                MbTrimRuntime::trimCharsHelper($context),
                $str,
                $whatPtr
            );
        }

        return self::materializeOwnedString($context, $resultStr);
    }

    /**
     * @param JITVariable[] $args
     */
    private static function tryCompileTimeFold(Context $context, int $mode, string $function, array $args): ?Value
    {
        if (!isset($args[0])) {
            return null;
        }
        // Zend 8.4 soft-null + DEP (#24176). Do not fold null — fall through to runtime.
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            return null;
        }
        $string = JitStringArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString ?? null;
        if (null === $string) {
            return null;
        }
        $what = self::compileTimeWhat($args, 1);
        if (false === $what) {
            return null;
        }
        $encoding = self::compileTimeEncoding($args, 2);
        if (null === $encoding) {
            return null;
        }

        // Unknown / unsupported encoding → runtime NestedJIT assert (catchable ValueError) (#35199).
        if (null === self::canonicalTrimEncoding($encoding)) {
            return null;
        }

        return self::materializeString(
            $context,
            VmMbstring::trimString($string, $what, $encoding, $mode, $function)
        );
    }

    /**
     * @param JITVariable[] $args
     *
     * @return null|string|false null = use default trim set; false = not foldable
     */
    private static function compileTimeWhat(array $args, int $index): null|string|false
    {
        if (!isset($args[$index])) {
            return null;
        }
        if (JITVariable::TYPE_NULL === $args[$index]->type || ($args[$index]->isNullConstant ?? false)) {
            return null;
        }
        $lit = JitStringArg::compileTimeLiteral($args[$index])
            ?? $args[$index]->compileTimeString
            ?? null;
        if (null === $lit) {
            return false;
        }

        return $lit;
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

        return JitStringArg::compileTimeLiteral($args[$index])
            ?? $args[$index]->compileTimeString
            ?? null;
    }

    /**
     * Literal UTF-8/ASCII/8BIT → constant string (no assert); otherwise NestedJIT encoding + assert (#35199).
     *
     * @param list<JITVariable> $args
     * @return array{0: Value, 1: bool} encoding ptr, needsAssert
     */
    private static function encodingPtr(Context $context, array $args, int $argc, string $function): array
    {
        if ($argc < 3 || JITVariable::TYPE_NULL === $args[2]->type || ($args[2]->isNullConstant ?? false)) {
            return [$context->builder->load($context->constantStringFromString('UTF-8')), false];
        }

        $encodingLit = JitStringArg::compileTimeLiteral($args[2]);
        if (null !== $encodingLit) {
            $canonical = self::canonicalTrimEncoding($encodingLit);
            if (null !== $canonical) {
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

    private static function canonicalTrimEncoding(string $encoding): ?string
    {
        $upper = \strtoupper($encoding);
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
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $context->builder->load($context->constantStringFromString($str))
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }
}
