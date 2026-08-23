<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbSearchRuntime;
use PHPCompiler\JIT\Builtin\StringStrpos;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mbstring search builtins (#7015).
 *
 * Compile-time fold via {@see VmMbstring}; mb_strpos() runtime via NestedJIT (#34146 leftover of #27187).
 */
final class JitMbSearch
{
    /**
     * mb_strpos() — fold literals, else NestedJIT {@see MbSearchJitHelper::strposArgv}.
     *
     * @param list<JITVariable> $args
     */
    public static function invokeStrpos(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('mb_strpos() requires two to four arguments');
        }
        $folded = self::tryStrposFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        // Soft-null DEP+coerce on 8.4 (php-src mbstring.c / #21197).
        $hay = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'mb_strpos', 0, 'haystack');
        $needle = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'mb_strpos', 1, 'needle');
        $i64 = $context->getTypeFromString('int64');
        $offset = $argc >= 3
            ? JitStrictIntArg::lower($context, $args[2], 'mb_strpos', 3, 'offset')
            : $i64->constInt(0, false);
        if ($argc >= 4) {
            if (JITVariable::TYPE_NULL === $args[3]->type || ($args[3]->isNullConstant ?? false)) {
                $encoding = 'UTF-8';
            } elseif (JITVariable::TYPE_STRING !== $args[3]->type) {
                throw new \LogicException('mb_strpos() encoding must be a string literal in this compiler build');
            } else {
                $encoding = $args[3]->compileTimeString ?? null;
                if (null === $encoding) {
                    throw new \LogicException('mb_strpos() encoding must be a string literal in this compiler build');
                }
            }
        } else {
            $encoding = 'UTF-8';
        }
        self::assertSupportedEncoding($encoding);

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbSearchRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $encPtr = $context->builder->load($context->constantStringFromString($encoding));
        $found = JitNestedHelperCoerce::callHelper(
            $context,
            MbSearchRuntime::strposHelper($context),
            [$hay, $needle, $offset, $encPtr]
        );

        return StringStrpos::boxFoundOffset($context, $found);
    }

    /**
     * mb_stripos() — fold literals, else NestedJIT {@see MbSearchJitHelper::striposArgv} (#34158).
     *
     * @param list<JITVariable> $args
     */
    public static function invokeStripos(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('mb_stripos() requires two to four arguments');
        }
        $folded = self::tryStriposFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        $hay = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'mb_stripos', 0, 'haystack');
        $needle = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'mb_stripos', 1, 'needle');
        $i64 = $context->getTypeFromString('int64');
        $offset = $argc >= 3
            ? JitStrictIntArg::lower($context, $args[2], 'mb_stripos', 3, 'offset')
            : $i64->constInt(0, false);
        if ($argc >= 4) {
            if (JITVariable::TYPE_NULL === $args[3]->type || ($args[3]->isNullConstant ?? false)) {
                $encoding = 'UTF-8';
            } elseif (JITVariable::TYPE_STRING !== $args[3]->type) {
                throw new \LogicException('mb_stripos() encoding must be a string literal in this compiler build');
            } else {
                $encoding = $args[3]->compileTimeString ?? null;
                if (null === $encoding) {
                    throw new \LogicException('mb_stripos() encoding must be a string literal in this compiler build');
                }
            }
        } else {
            $encoding = 'UTF-8';
        }
        self::assertSupportedEncoding($encoding);

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbSearchRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $encPtr = $context->builder->load($context->constantStringFromString($encoding));
        $found = JitNestedHelperCoerce::callHelper(
            $context,
            MbSearchRuntime::striposHelper($context),
            [$hay, $needle, $offset, $encPtr]
        );

        return StringStrpos::boxFoundOffset($context, $found);
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryStrposFold(Context $context, array $args): ?Value
    {
        $hay = self::compileTimeString($args, 0);
        $needle = self::compileTimeString($args, 1);
        if (null === $hay || null === $needle) {
            return null;
        }
        $offset = self::compileTimeOffset($context, $args, 2);
        if (null === $offset) {
            return null;
        }
        $encoding = self::compileTimeEncoding($args, 3);
        if (null === $encoding) {
            return null;
        }

        return self::intOrFalse($context, VmMbstring::strpos($hay, $needle, $offset, $encoding));
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryStriposFold(Context $context, array $args): ?Value
    {
        $hay = self::compileTimeString($args, 0);
        $needle = self::compileTimeString($args, 1);
        if (null === $hay || null === $needle) {
            return null;
        }
        $offset = self::compileTimeOffset($context, $args, 2);
        if (null === $offset) {
            return null;
        }
        $encoding = self::compileTimeEncoding($args, 3);
        if (null === $encoding) {
            return null;
        }

        return self::intOrFalse($context, VmMbstring::stripos($hay, $needle, $offset, $encoding));
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryStrrposFold(Context $context, array $args): ?Value
    {
        $hay = self::compileTimeString($args, 0);
        $needle = self::compileTimeString($args, 1);
        if (null === $hay || null === $needle) {
            return null;
        }
        $offset = self::compileTimeOffset($context, $args, 2);
        if (null === $offset) {
            return null;
        }
        $encoding = self::compileTimeEncoding($args, 3);
        if (null === $encoding) {
            return null;
        }

        return self::intOrFalse($context, VmMbstring::strrpos($hay, $needle, $offset, $encoding));
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryStrrichrFold(Context $context, array $args): ?Value
    {
        return self::tryStrchrFamilyFold($context, $args, static function (
            string $hay,
            string $needle,
            bool $part,
            string $encoding
        ) {
            return VmMbstring::strrichr($hay, $needle, $part, $encoding);
        });
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryStrstrFold(Context $context, array $args): ?Value
    {
        return self::tryStrchrFamilyFold($context, $args, static function (
            string $hay,
            string $needle,
            bool $part,
            string $encoding
        ) {
            return VmMbstring::strstr($hay, $needle, $part, $encoding);
        });
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryStristrFold(Context $context, array $args): ?Value
    {
        return self::tryStrchrFamilyFold($context, $args, static function (
            string $hay,
            string $needle,
            bool $part,
            string $encoding
        ) {
            return VmMbstring::stristr($hay, $needle, $part, $encoding);
        });
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryStrrchrFold(Context $context, array $args): ?Value
    {
        return self::tryStrchrFamilyFold($context, $args, static function (
            string $hay,
            string $needle,
            bool $part,
            string $encoding
        ) {
            return VmMbstring::strrchr($hay, $needle, $part, $encoding);
        });
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryStrriposFold(Context $context, array $args): ?Value
    {
        $hay = self::compileTimeString($args, 0);
        $needle = self::compileTimeString($args, 1);
        if (null === $hay || null === $needle) {
            return null;
        }
        $offset = self::compileTimeOffset($context, $args, 2);
        if (null === $offset) {
            return null;
        }
        $encoding = self::compileTimeEncoding($args, 3);
        if (null === $encoding) {
            return null;
        }

        return self::intOrFalse($context, VmMbstring::strripos($hay, $needle, $offset, $encoding));
    }

    /**
     * @param JITVariable[] $args
     * @param callable(string, string, bool, string): (string|false) $compute
     */
    private static function tryStrchrFamilyFold(Context $context, array $args, callable $compute): ?Value
    {
        $hay = self::compileTimeString($args, 0);
        $needle = self::compileTimeString($args, 1);
        if (null === $hay || null === $needle) {
            return null;
        }
        $part = self::compileTimePart($args, 2);
        if (null === $part) {
            return null;
        }
        $encoding = self::compileTimeEncoding($args, 3);
        if (null === $encoding) {
            return null;
        }
        $result = $compute($hay, $needle, $part, $encoding);
        if (false === $result) {
            return $context->getTypeFromString('bool')->constInt(0, false);
        }

        return $context->builder->load($context->constantStringFromString($result));
    }

    /**
     * @param JITVariable[] $args
     */
    private static function compileTimeString(array $args, int $index): ?string
    {
        if (!isset($args[$index])) {
            return null;
        }
        if (JITVariable::TYPE_STRING !== $args[$index]->type) {
            return null;
        }

        return $args[$index]->compileTimeString ?? null;
    }

    /**
     * @param JITVariable[] $args
     */
    private static function compileTimeOffset(Context $context, array $args, int $index): ?int
    {
        if (!isset($args[$index])) {
            return 0;
        }
        $arg = $args[$index];
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
     * @param JITVariable[] $args
     */
    private static function compileTimePart(array $args, int $index): ?bool
    {
        if (!isset($args[$index])) {
            return false;
        }
        $arg = $args[$index];
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $const = $arg->value;
            if ($const instanceof Value && $const->isConstant()) {
                return 0 !== (int) $const->constInt();
            }
        }

        return null;
    }

    /**
     * @param JITVariable[] $args
     */
    private static function compileTimeEncoding(array $args, int $index): ?string
    {
        if (!isset($args[$index])) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_STRING !== $args[$index]->type) {
            return null;
        }

        return $args[$index]->compileTimeString ?? null;
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
                'mb_strpos() JIT only supports UTF-8, ASCII, or 8BIT encoding literals in this compiler build'
            );
        }
    }
}
