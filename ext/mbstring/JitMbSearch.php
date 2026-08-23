<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbSearchRuntime;
use PHPCompiler\JIT\Builtin\StringStrpos;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mbstring search builtins (#7015).
 *
 * Compile-time fold via {@see VmMbstring}; mb_strpos()/mb_strstr()/mb_strrchr() runtime via NestedJIT
 * (#34146 leftover of #27187; #34211).
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
     * mb_strrpos() — fold literals, else NestedJIT {@see MbSearchJitHelper::strrposArgv} (#34166).
     *
     * @param list<JITVariable> $args
     */
    public static function invokeStrrpos(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('mb_strrpos() requires two to four arguments');
        }
        $folded = self::tryStrrposFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        $hay = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'mb_strrpos', 0, 'haystack');
        $needle = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'mb_strrpos', 1, 'needle');
        $i64 = $context->getTypeFromString('int64');
        $offset = $argc >= 3
            ? JitStrictIntArg::lower($context, $args[2], 'mb_strrpos', 3, 'offset')
            : $i64->constInt(0, false);
        if ($argc >= 4) {
            if (JITVariable::TYPE_NULL === $args[3]->type || ($args[3]->isNullConstant ?? false)) {
                $encoding = 'UTF-8';
            } elseif (JITVariable::TYPE_STRING !== $args[3]->type) {
                throw new \LogicException('mb_strrpos() encoding must be a string literal in this compiler build');
            } else {
                $encoding = $args[3]->compileTimeString ?? null;
                if (null === $encoding) {
                    throw new \LogicException('mb_strrpos() encoding must be a string literal in this compiler build');
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
            MbSearchRuntime::strrposHelper($context),
            [$hay, $needle, $offset, $encPtr]
        );

        return StringStrpos::boxFoundOffset($context, $found);
    }

    /**
     * mb_strripos() — fold literals, else NestedJIT {@see MbSearchJitHelper::strriposArgv}.
     *
     * @param list<JITVariable> $args
     */
    public static function invokeStrripos(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('mb_strripos() requires two to four arguments');
        }
        $folded = self::tryStrriposFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        $hay = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'mb_strripos', 0, 'haystack');
        $needle = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'mb_strripos', 1, 'needle');
        $i64 = $context->getTypeFromString('int64');
        $offset = $argc >= 3
            ? JitStrictIntArg::lower($context, $args[2], 'mb_strripos', 3, 'offset')
            : $i64->constInt(0, false);
        if ($argc >= 4) {
            if (JITVariable::TYPE_NULL === $args[3]->type || ($args[3]->isNullConstant ?? false)) {
                $encoding = 'UTF-8';
            } elseif (JITVariable::TYPE_STRING !== $args[3]->type) {
                throw new \LogicException('mb_strripos() encoding must be a string literal in this compiler build');
            } else {
                $encoding = $args[3]->compileTimeString ?? null;
                if (null === $encoding) {
                    throw new \LogicException('mb_strripos() encoding must be a string literal in this compiler build');
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
            MbSearchRuntime::strriposHelper($context),
            [$hay, $needle, $offset, $encPtr]
        );

        return StringStrpos::boxFoundOffset($context, $found);
    }

    /**
     * mb_strstr() — fold literals, else NestedJIT {@see MbSearchJitHelper::strstrArgv} (#34211).
     *
     * @param list<JITVariable> $args
     */
    public static function invokeStrstr(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('mb_strstr() requires two to four arguments');
        }
        $folded = self::tryStrstrFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        $hay = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'mb_strstr', 0, 'haystack');
        $needle = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'mb_strstr', 1, 'needle');
        $beforeNeedle = $argc >= 3
            ? JitBoolArg::lowerZParamBool($context, $args[2], 'mb_strstr', 'before_needle', 3)
            : $context->constantFromBool(false);
        if ($argc >= 4) {
            if (JITVariable::TYPE_NULL === $args[3]->type || ($args[3]->isNullConstant ?? false)) {
                $encoding = 'UTF-8';
            } elseif (JITVariable::TYPE_STRING !== $args[3]->type) {
                throw new \LogicException('mb_strstr() encoding must be a string literal in this compiler build');
            } else {
                $encoding = $args[3]->compileTimeString ?? null;
                if (null === $encoding) {
                    throw new \LogicException('mb_strstr() encoding must be a string literal in this compiler build');
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
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            MbSearchRuntime::strstrHelper($context),
            [$hay, $needle, $beforeNeedle, $encPtr]
        );

        return self::boxStringOrFalse($context, $raw);
    }

    /**
     * mb_stristr() — fold literals, else NestedJIT {@see MbSearchJitHelper::stristrArgv}.
     *
     * @param list<JITVariable> $args
     */
    public static function invokeStristr(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('mb_stristr() requires two to four arguments');
        }
        $folded = self::tryStristrFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        $hay = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'mb_stristr', 0, 'haystack');
        $needle = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'mb_stristr', 1, 'needle');
        $beforeNeedle = $argc >= 3
            ? JitBoolArg::lowerZParamBool($context, $args[2], 'mb_stristr', 'before_needle', 3)
            : $context->constantFromBool(false);
        if ($argc >= 4) {
            if (JITVariable::TYPE_NULL === $args[3]->type || ($args[3]->isNullConstant ?? false)) {
                $encoding = 'UTF-8';
            } elseif (JITVariable::TYPE_STRING !== $args[3]->type) {
                throw new \LogicException('mb_stristr() encoding must be a string literal in this compiler build');
            } else {
                $encoding = $args[3]->compileTimeString ?? null;
                if (null === $encoding) {
                    throw new \LogicException('mb_stristr() encoding must be a string literal in this compiler build');
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
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            MbSearchRuntime::stristrHelper($context),
            [$hay, $needle, $beforeNeedle, $encPtr]
        );

        return self::boxStringOrFalse($context, $raw);
    }

    /**
     * mb_strrchr() — fold literals, else NestedJIT {@see MbSearchJitHelper::strrchrArgv} (#20006 leftover).
     *
     * @param list<JITVariable> $args
     */
    public static function invokeStrrchr(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('mb_strrchr() requires two to four arguments');
        }
        $folded = self::tryStrrchrFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        $hay = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'mb_strrchr', 0, 'haystack');
        $needle = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'mb_strrchr', 1, 'needle');
        $beforeNeedle = $argc >= 3
            ? JitBoolArg::lowerZParamBool($context, $args[2], 'mb_strrchr', 'before_needle', 3)
            : $context->constantFromBool(false);
        if ($argc >= 4) {
            if (JITVariable::TYPE_NULL === $args[3]->type || ($args[3]->isNullConstant ?? false)) {
                $encoding = 'UTF-8';
            } elseif (JITVariable::TYPE_STRING !== $args[3]->type) {
                throw new \LogicException('mb_strrchr() encoding must be a string literal in this compiler build');
            } else {
                $encoding = $args[3]->compileTimeString ?? null;
                if (null === $encoding) {
                    throw new \LogicException('mb_strrchr() encoding must be a string literal in this compiler build');
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
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            MbSearchRuntime::strrchrHelper($context),
            [$hay, $needle, $beforeNeedle, $encPtr]
        );

        return self::boxStringOrFalse($context, $raw);
    }

    /**
     * mb_strrichr() — fold literals, else NestedJIT {@see MbSearchJitHelper::strrichrArgv} (#7015 leftover).
     *
     * @param list<JITVariable> $args
     */
    public static function invokeStrrichr(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('mb_strrichr() requires two to four arguments');
        }
        $folded = self::tryStrrichrFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        $hay = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'mb_strrichr', 0, 'haystack');
        $needle = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'mb_strrichr', 1, 'needle');
        $beforeNeedle = $argc >= 3
            ? JitBoolArg::lowerZParamBool($context, $args[2], 'mb_strrichr', 'before_needle', 3)
            : $context->constantFromBool(false);
        if ($argc >= 4) {
            if (JITVariable::TYPE_NULL === $args[3]->type || ($args[3]->isNullConstant ?? false)) {
                $encoding = 'UTF-8';
            } elseif (JITVariable::TYPE_STRING !== $args[3]->type) {
                throw new \LogicException('mb_strrichr() encoding must be a string literal in this compiler build');
            } else {
                $encoding = $args[3]->compileTimeString ?? null;
                if (null === $encoding) {
                    throw new \LogicException('mb_strrichr() encoding must be a string literal in this compiler build');
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
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            MbSearchRuntime::strrichrHelper($context),
            [$hay, $needle, $beforeNeedle, $encPtr]
        );

        return self::boxStringOrFalse($context, $raw);
    }

    /**
     * NestedJIT string|false → `__value__*` (peer StringHex2bin / #34211).
     */
    private static function boxStringOrFalse(Context $context, Value $raw): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_strstr_box');
        $i32 = $context->getTypeFromString('int32');
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $isMiss = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $missBb = BasicBlockHelper::append($context, 'mb_strstr_miss');
        $hitBb = BasicBlockHelper::append($context, 'mb_strstr_hit');
        $doneBb = BasicBlockHelper::append($context, 'mb_strstr_done');
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
