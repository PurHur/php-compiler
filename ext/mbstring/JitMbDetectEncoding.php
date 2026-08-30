<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbDetectEncodingRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TypeErrorRaise;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_detect_encoding() (#3075, #34358 / #35856 leftover).
 *
 * Compile-time fold when $string is a literal; runtime haystack via NestedJIT
 * {@see MbDetectEncodingJitHelper} (peer {@see JitMbScrub}). Encodings list +
 * $strict stay compile-time (NestedJIT of MbstringState / arrays aborts).
 *
 * Link NestedJIT **before** lowering args — NestedJIT can invalidate prior IR (#34270 / #35856).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_detect_encoding)
 */
final class JitMbDetectEncoding
{
    /** @var int Per-function CFG disambiguator — duplicate block names break multi-call scripts (#34358). */
    private static int $boxBlockSuffix = 0;

    private const SUPPORTED = [
        'ASCII' => true,
        'UTF-8' => true,
        'ISO-8859-1' => true,
        '8BIT' => true,
    ];

    /**
     * @param list<JITVariable> $args
     */
    public static function invoke(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('mb_detect_encoding() expects 1 to 3 arguments in this compiler build');
        }

        $folded = self::tryCompileTimeFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        $order = self::compileTimeOrder($context, $args, $argc);
        if (null === $order) {
            throw new \LogicException(
                'mb_detect_encoding() encodings must be a compile-time string or array of string literals in this compiler build'
            );
        }
        foreach ($order as $enc) {
            if (!isset(self::SUPPORTED[$enc])) {
                throw new \LogicException(
                    'mb_detect_encoding() NestedJIT only supports ASCII, UTF-8, ISO-8859-1, and 8BIT encodings in this compiler build'
                );
            }
        }

        // Link NestedJIT helpers before lowering args — NestedJIT can invalidate prior IR (#34270 / #35856).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbDetectEncodingRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_detect_encoding_runtime');

        $str = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[0],
            'mb_detect_encoding',
            0,
            'string'
        );

        $orderPtr = $context->builder->load($context->constantStringFromString(self::orderCodes($order)));
        $strictPtr = self::strictFlagString($context, $args);
        $resultStr = $context->builder->call(
            MbDetectEncodingRuntime::detectHelper($context),
            $str,
            $orderPtr,
            $strictPtr
        );

        return self::boxDetectResult($context, $resultStr);
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryCompileTimeFold(Context $context, array $args): ?Value
    {
        if (!isset($args[0])) {
            return null;
        }
        if (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant) {
            if ($context->callerStrictTypes) {
                return JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'mb_detect_encoding', 0, 'string');
            }
            $string = '';
        } else {
            $string = JitStringArg::compileTimeLiteral($args[0]);
            if (null === $string) {
                return null;
            }
        }
        $order = self::compileTimeOrder($context, $args, \count($args));
        if (null === $order) {
            return null;
        }
        $strict = self::compileTimeStrict($args);
        if (null === $strict) {
            return null;
        }
        $result = VmMbstring::detectEncoding($string, $order, $strict);
        if (false === $result) {
            return $context->constantFromBool(false);
        }

        return $context->builder->load($context->constantStringFromString($result));
    }

    /**
     * @param list<JITVariable> $args
     *
     * @return list<string>|null
     */
    private static function compileTimeOrder(Context $context, array $args, int $argc): ?array
    {
        if ($argc < 2 || JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
            return MbstringAotFoldState::detectOrder($context) ?? MbstringState::detectOrder();
        }
        $lit = JitStringArg::compileTimeLiteral($args[1]);
        if (null !== $lit) {
            return MbstringEncodingRegistry::parseOrderList('mb_detect_encoding', 1, $lit);
        }
        $fromNative = self::compileTimeOrderFromNativeArray($context, $args[1]);
        if (null !== $fromNative) {
            return $fromNative;
        }
        $arr = $args[1]->compileTimeArray ?? null;
        if (null === $arr) {
            return null;
        }
        $order = [];
        foreach ($arr as $elem) {
            if (\is_string($elem)) {
                $s = $elem;
            } elseif ($elem instanceof JITVariable) {
                $s = JitStringArg::compileTimeLiteral($elem);
                if (null === $s) {
                    return null;
                }
            } else {
                return null;
            }
            $canonical = MbstringEncodingRegistry::resolve($s);
            if (null === $canonical) {
                TypeErrorRaise::emitValueError(
                    $context,
                    sprintf(
                        'mb_detect_encoding(): Argument #2 ($encodings) contains invalid encoding "%s"',
                        $s
                    )
                );
                BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_detect_enc_bad_list');

                return null;
            }
            $order[] = $canonical;
        }
        if ([] === $order) {
            return null;
        }

        return $order;
    }

    /**
     * Packed native-array encoding list (`['UTF-8','ASCII']`) via dimFetch (#34358).
     *
     * @return list<string>|null
     */
    private static function compileTimeOrderFromNativeArray(Context $context, JITVariable $arg): ?array
    {
        if (0 === ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            return null;
        }
        $n = $arg->nextFreeElement;
        if ($n <= 0) {
            return null;
        }
        $order = [];
        for ($i = 0; $i < $n; ++$i) {
            $elem = $arg->dimFetch(JITVariable::fromConstantInt($context, $i));
            $s = JitStringArg::compileTimeLiteral($elem);
            if (null === $s) {
                return null;
            }
            $canonical = MbstringEncodingRegistry::resolve($s);
            if (null === $canonical) {
                return null;
            }
            $order[] = $canonical;
        }

        return $order;
    }

    /**
     * Packed NestedJIT order: A=ASCII U=UTF-8 L=ISO-8859-1 B=8BIT (#34358).
     *
     * @param list<string> $order
     */
    private static function orderCodes(array $order): string
    {
        $out = '';
        foreach ($order as $enc) {
            $out .= match ($enc) {
                'ASCII' => 'A',
                'UTF-8' => 'U',
                'ISO-8859-1' => 'L',
                '8BIT' => 'B',
                default => '',
            };
        }

        return $out;
    }

    /**
     * NestedJIT third arg must stay a string ("0"/"1") — int boxes all params (#35856).
     *
     * @param list<JITVariable> $args
     */
    private static function strictFlagString(Context $context, array $args): Value
    {
        $folded = self::compileTimeStrict($args);
        if (null !== $folded) {
            return $context->builder->load($context->constantStringFromString($folded ? '1' : '0'));
        }
        if (isset($args[2]) && JITVariable::TYPE_NATIVE_BOOL === $args[2]->type) {
            $i1 = $context->helper->loadValue($args[2]);
            $one = $context->builder->load($context->constantStringFromString('1'));
            $zero = $context->builder->load($context->constantStringFromString('0'));

            return $context->builder->select($i1, $one, $zero);
        }
        throw new \LogicException(
            'mb_detect_encoding() strict must be a bool in this compiler build'
        );
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function compileTimeStrict(array $args): ?bool
    {
        if (!isset($args[2]) || JITVariable::TYPE_NULL === $args[2]->type || ($args[2]->isNullConstant ?? false)) {
            return false;
        }
        if (null !== ($args[2]->compileTimeBool ?? null)) {
            return (bool) $args[2]->compileTimeBool;
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $args[2]->type && JITVariable::KIND_VALUE === $args[2]->kind) {
            $const = $args[2]->value;
            if ($const instanceof Value && $const->isConstant()) {
                return 0 !== (int) $const->constInt();
            }
        }

        return null;
    }

    private static function boxDetectResult(Context $context, Value $resultStr): Value
    {
        $len = $context->builder->call($context->lookupFunction('__string__strlen'), $resultStr);
        $zero = $context->getTypeFromString('int64')->constInt(0, false);
        $isFalse = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $fn = BasicBlockHelper::parentFunction($context);
        $suffix = ++self::$boxBlockSuffix;
        $falseBlock = $fn->appendBasicBlock('mb_detect_false_'.$suffix);
        $strBlock = $fn->appendBasicBlock('mb_detect_str_'.$suffix);
        $doneBlock = $fn->appendBasicBlock('mb_detect_done_'.$suffix);
        $context->builder->branchIf($isFalse, $falseBlock, $strBlock);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $context->builder->positionAtEnd($falseBlock);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($strBlock);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $resultStr);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_detect_encoding_done');

        return $ptr;
    }
}
