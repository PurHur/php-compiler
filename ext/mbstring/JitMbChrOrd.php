<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbChrOrdRuntime;
use PHPCompiler\JIT\Builtin\StringStrpos;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_chr() / mb_ord() (php-src ext/mbstring/mbstring.c; #30759 / #34243 / #34250).
 *
 * Compile-time fold via {@see VmMbstring}; runtime via NestedJIT {@see MbChrOrdJitHelper}.
 * Peer {@see JitMbSearch}.
 */
final class JitMbChrOrd
{
    /**
     * mb_chr() — fold literals, else NestedJIT {@see MbChrOrdJitHelper::chrArgv} (#34250).
     *
     * @param list<JITVariable> $args
     */
    public static function invokeChr(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('mb_chr() requires one or two arguments');
        }
        $folded = self::tryChrFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        $codepoint = JitStrictIntArg::lower(
            $context,
            $args[0],
            'mb_chr',
            1,
            'codepoint'
        );
        if ($argc >= 2) {
            if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
                $encoding = 'UTF-8';
            } elseif (JITVariable::TYPE_STRING !== $args[1]->type) {
                throw new \LogicException('mb_chr() encoding must be a string literal in this compiler build');
            } else {
                $encoding = $args[1]->compileTimeString ?? null;
                if (null === $encoding) {
                    throw new \LogicException('mb_chr() encoding must be a string literal in this compiler build');
                }
            }
        } else {
            $encoding = 'UTF-8';
        }
        self::assertSupportedEncoding($encoding);

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbChrOrdRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $encPtr = $context->builder->load($context->constantStringFromString($encoding));
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            MbChrOrdRuntime::chrHelper($context),
            [$codepoint, $encPtr]
        );

        return self::boxStringOrFalse($context, $raw);
    }

    /**
     * NestedJIT string|false → `__value__*` (peer JitMbSearch / #34211).
     */
    private static function boxStringOrFalse(Context $context, Value $raw): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_chr_box');
        $i32 = $context->getTypeFromString('int32');
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $isMiss = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $missBb = BasicBlockHelper::append($context, 'mb_chr_miss');
        $hitBb = BasicBlockHelper::append($context, 'mb_chr_hit');
        $doneBb = BasicBlockHelper::append($context, 'mb_chr_done');
        $context->builder->branchIf($isMiss, $missBb, $hitBb);

        $context->builder->positionAtEnd($missBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $ptr,
            $i32->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($hitBb);
        $strPtr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $strPtr);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $ptr;
    }

    /**
     * mb_ord() — fold literals, else NestedJIT {@see MbChrOrdJitHelper::ordArgv} (#34243).
     *
     * @param list<JITVariable> $args
     */
    public static function invokeOrd(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('mb_ord() requires one or two arguments');
        }
        $folded = self::tryOrdFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        $string = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'mb_ord', 0, 'string');
        if ($argc >= 2) {
            if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
                $encoding = 'UTF-8';
            } elseif (JITVariable::TYPE_STRING !== $args[1]->type) {
                throw new \LogicException('mb_ord() encoding must be a string literal in this compiler build');
            } else {
                $encoding = $args[1]->compileTimeString ?? null;
                if (null === $encoding) {
                    throw new \LogicException('mb_ord() encoding must be a string literal in this compiler build');
                }
            }
        } else {
            $encoding = 'UTF-8';
        }
        self::assertSupportedEncoding($encoding);

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbChrOrdRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $encPtr = $context->builder->load($context->constantStringFromString($encoding));
        $found = JitNestedHelperCoerce::callHelper(
            $context,
            MbChrOrdRuntime::ordHelper($context),
            [$string, $encPtr]
        );

        return StringStrpos::boxFoundOffset($context, $found);
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryChrFold(Context $context, array $args): ?Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            return null;
        }
        $codepoint = self::compileTimeCodepoint($context, $args[0]);
        if (null === $codepoint) {
            return null;
        }
        $encoding = self::compileTimeEncoding($args, 1);
        if (null === $encoding) {
            return null;
        }
        // Unknown encoding → fall through (avoid ValueError during IR fold; peer #23883).
        if (!MbstringEncodingRegistry::isValid($encoding)) {
            return null;
        }

        return self::stringOrFalse($context, VmMbstring::chr($codepoint, $encoding));
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryOrdFold(Context $context, array $args): ?Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            return null;
        }
        if (
            JITVariable::TYPE_STRING !== $args[0]->type
            || null === ($args[0]->compileTimeString ?? null)
        ) {
            return null;
        }
        $encoding = self::compileTimeEncoding($args, 1);
        if (null === $encoding) {
            return null;
        }
        if (!MbstringEncodingRegistry::isValid($encoding)) {
            return null;
        }

        return self::intOrFalse($context, VmMbstring::ord($args[0]->compileTimeString, $encoding));
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
            return null;
        }
        if (JITVariable::TYPE_STRING !== $args[$index]->type) {
            return null;
        }

        return $args[$index]->compileTimeString ?? null;
    }

    private static function compileTimeCodepoint(Context $context, JITVariable $arg): ?int
    {
        if (null !== ($arg->compileTimeLong ?? null)) {
            return $arg->compileTimeLong;
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $arg->type || JITVariable::KIND_VALUE !== $arg->kind) {
            return null;
        }
        // Prefer LLVMIsAConstantInt — Value::isConstant()/constInt() miss some AOT i64 literals (#27187).
        $lib = $context->llvm->lib;
        if (null === $lib->LLVMIsAConstantInt($arg->value->value)) {
            return null;
        }

        return (int) $lib->LLVMConstIntGetSExtValue($arg->value->value);
    }

    /**
     * @param string|false $result
     */
    private static function stringOrFalse(Context $context, string|false $result): Value
    {
        if (false === $result) {
            return $context->getTypeFromString('bool')->constInt(0, false);
        }

        return $context->builder->load($context->constantStringFromString($result));
    }

    /**
     * @param int|false $result
     */
    private static function intOrFalse(Context $context, int|false $result): Value
    {
        if (false === $result) {
            return $context->getTypeFromString('bool')->constInt(0, false);
        }

        return $context->constantFromInteger($result, 'int64');
    }

    private static function assertSupportedEncoding(string $encoding): void
    {
        if ('UTF-8' !== $encoding && 'ASCII' !== $encoding && '8BIT' !== $encoding) {
            throw new \LogicException(
                'mb_chr()/mb_ord() JIT only supports UTF-8, ASCII, or 8BIT encoding literals in this compiler build'
            );
        }
    }
}
