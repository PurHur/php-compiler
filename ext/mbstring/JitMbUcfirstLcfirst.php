<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbCaseRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_ucfirst() / mb_lcfirst() (php-src ext/mbstring/mbstring.c; #27330, #34259).
 *
 * Compile-time fold for string literals; runtime via NestedJIT {@see MbCaseJitHelper} (peer {@see JitMbCase}).
 * Runtime encoding via NestedJIT (#34858 leftover of #34625).
 */
final class JitMbUcfirstLcfirst
{
    /**
     * @param list<JITVariable> $args
     * @param callable(string, string): string $fold
     */
    public static function invoke(Context $context, string $function, callable $fold, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException($function.'() requires one or two arguments');
        }

        // Soft-null — do not fold; recover via NestedJIT / VM (#24176).
        $nullInput = JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false);
        $strLit = $nullInput ? null : ($args[0]->compileTimeString ?? null);
        $encLit = self::compileTimeEncoding($args, $argc);
        // Invalid encoding must not fold via VmMbstring (uncaught at compile) — NestedJIT (#34858).
        if (null !== $strLit && null !== $encLit && self::isSupportedEncoding($encLit)) {
            return self::materializeString($context, $fold($strLit, $encLit));
        }

        $str = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], $function, 0, 'string');
        [$encPtr, $needsAssert] = self::encodingPtr($context, $args, $argc, $function);

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbCaseRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, $function.'_runtime');

        if ($needsAssert) {
            $fnName = $context->builder->load($context->constantStringFromString($function));
            $context->builder->call(
                MbCaseRuntime::assertEncodingHelper($context),
                $encPtr,
                $fnName
            );
        }

        $helper = 'mb_ucfirst' === $function
            ? MbCaseRuntime::ucfirstHelper($context)
            : MbCaseRuntime::lcfirstHelper($context);
        $resultStr = $context->builder->call($helper, $str, $encPtr);

        return self::materializeOwnedString($context, $resultStr);
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function compileTimeEncoding(array $args, int $argc): ?string
    {
        if ($argc < 2) {
            return MbstringState::internalEncoding();
        }
        if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
            return MbstringState::internalEncoding();
        }
        $lit = JitStringArg::compileTimeLiteral($args[1]);
        if (null === $lit) {
            return null;
        }
        $canonical = MbstringEncodingRegistry::resolve($lit);

        return null !== $canonical ? $canonical : $lit;
    }

    /**
     * @param list<JITVariable> $args
     * @return array{0: Value, 1: bool}
     */
    private static function encodingPtr(Context $context, array $args, int $argc, string $function): array
    {
        if ($argc < 2 || JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
            $encoding = MbstringAotFoldState::internalEncoding($context) ?? MbstringState::internalEncoding();
            self::assertSupportedEncoding($encoding);

            return [$context->builder->load($context->constantStringFromString($encoding)), false];
        }

        $encodingLit = JitStringArg::compileTimeLiteral($args[1]);
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
                $args[1],
                $function,
                1,
                'encoding'
            ),
            true,
        ];
    }

    private static function isSupportedEncoding(string $encoding): bool
    {
        return 'UTF-8' === $encoding || 'ASCII' === $encoding || '8BIT' === $encoding;
    }

    private static function assertSupportedEncoding(string $encoding): void
    {
        if (!self::isSupportedEncoding($encoding)) {
            throw new \LogicException(
                'mb_ucfirst()/mb_lcfirst() JIT only supports UTF-8, ASCII, or 8BIT encoding literals in this compiler build'
            );
        }
    }

    private static function materializeString(Context $context, string $str): Value
    {
        return self::materializeOwnedString(
            $context,
            $context->builder->load($context->constantStringFromString($str))
        );
    }

    private static function materializeOwnedString(Context $context, Value $resultStr): Value
    {
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $resultStr);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }
}
