<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of __compiler_filter_parse_boolean_string (issue #4742).
 *
 * php-src: ext/filter/logical_filters.c — php_filter_boolean token match.
 * VM semantics: ext/filter/VmFilter::parseBooleanString().
 *
 * @return int32 -1 unknown, 0 false token, 1 true token
 */
final class StringFilterBoolean
{
    public static function ensureLinked(Context $context): void
    {
        $restore = $context->builder->getInsertBlock();
        self::implement($context);
        if (null !== $restore) {
            $terminator = $restore->getTerminator();
            if (null !== $terminator) {
                $context->builder->positionBefore($terminator);
            } else {
                $context->builder->positionAtEnd($restore);
            }
        }
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_filter_parse_boolean_string');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $strPtrTy = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($i32, false, $strPtrTy);
        $fn = $context->module->addFunction('__compiler_filter_parse_boolean_string', $ft);
        self::implementParse($context, $fn);
        self::registerLinkedRuntime($context);
    }

    private static function implementParse(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('filter_bool_entry');
        $context->builder->positionAtEnd($entry);

        $input = $fn->getParam(0);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($input, $map['length'])
        );
        $charPtr = $context->builder->structGep($input, $map['value']);
        $i32 = $context->getTypeFromString('int32');
        $unknown = $i32->constInt(-1, true);
        $falseVal = $i32->constInt(0, false);
        $trueVal = $i32->constInt(1, false);
        $result = self::emitLengthCascade($context, $fn, $len, $charPtr, $unknown, $trueVal, $falseVal);
        $context->builder->returnValue($result);
    }

    private static function emitLengthCascade(
        Context $context,
        Value $fn,
        Value $len,
        Value $charPtr,
        Value $unknown,
        Value $trueVal,
        Value $falseVal
    ): Value {
        $i32 = $context->getTypeFromString('int32');
        $lenTy = $len->typeOf();
        $blocks = [];
        for ($n = 0; $n <= 6; ++$n) {
            $blocks[$n] = $fn->appendBasicBlock('filter_bool_cascade_'.$n);
        }

        $context->builder->branch($blocks[0]);
        $incoming = [];
        $values = [];

        for ($n = 0; $n <= 5; ++$n) {
            $context->builder->positionAtEnd($blocks[$n]);
            $isLen = $context->builder->icmp(Builder::INT_EQ, $len, $lenTy->constInt($n, false));
            $matchBlock = $fn->appendBasicBlock('filter_bool_match_'.$n);
            $context->builder->branchIf($isLen, $matchBlock, $blocks[$n + 1]);

            $context->builder->positionAtEnd($matchBlock);
            $val = match ($n) {
                0 => $falseVal,
                1 => self::matchLen1($context, $charPtr, $unknown, $trueVal, $falseVal),
                2 => self::matchWords($context, $charPtr, 2, [
                    ['on', $trueVal],
                    ['no', $falseVal],
                ], $unknown),
                3 => self::matchWords($context, $charPtr, 3, [
                    ['yes', $trueVal],
                    ['off', $falseVal],
                ], $unknown),
                4 => self::matchWords($context, $charPtr, 4, [
                    ['true', $trueVal],
                ], $unknown),
                5 => self::matchWords($context, $charPtr, 5, [
                    ['false', $falseVal],
                ], $unknown),
                default => $unknown,
            };
            $incoming[] = $context->builder->getInsertBlock();
            $values[] = $val;
        }

        $context->builder->positionAtEnd($blocks[6]);
        $incoming[] = $blocks[6];
        $values[] = $unknown;

        $done = $fn->appendBasicBlock('filter_bool_cascade_done');
        foreach ($incoming as $i => $block) {
            $context->builder->positionAtEnd($block);
            $context->builder->branch($done);
        }

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($i32);
        foreach ($values as $i => $val) {
            $phi->addIncoming($val, $incoming[$i]);
        }

        return $phi;
    }

    private static function matchLen1(
        Context $context,
        Value $charPtr,
        Value $unknown,
        Value $trueVal,
        Value $falseVal
    ): Value {
        $i8 = $context->getTypeFromString('int8');
        $b0 = $context->builder->load($charPtr);

        return $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $b0, $i8->constInt(ord('1'), false)),
            $trueVal,
            $context->builder->select(
                $context->builder->icmp(Builder::INT_EQ, $b0, $i8->constInt(ord('0'), false)),
                $falseVal,
                $unknown
            )
        );
    }

    /**
     * @param list<array{0: string, 1: Value}> $words
     */
    private static function matchWords(
        Context $context,
        Value $charPtr,
        int $litLen,
        array $words,
        Value $unknown
    ): Value {
        $result = $unknown;
        foreach ($words as [$word, $value]) {
            $match = self::bytesMatchLiteral($context, $charPtr, $word);
            $result = $context->builder->select($match, $value, $result);
        }

        return $result;
    }

    private static function bytesMatchLiteral(Context $context, Value $charPtr, string $word): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $match = $context->constantFromBool(true);
        $len = strlen($word);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $context->builder->load(
                $context->builder->gep($charPtr, $i64->constInt($i, false))
            );
            $lower = strtolower($word[$i]);
            $upper = strtoupper($word[$i]);
            $isCh = $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord($word[$i]), false)),
                $context->builder->or(
                    $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord($lower), false)),
                    $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord($upper), false))
                )
            );
            $match = $context->builder->and($match, $isCh);
        }

        return $match;
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__compiler_filter_parse_boolean_string');
        if (null === $fn) {
            throw new \LogicException('__compiler_filter_parse_boolean_string missing after filter boolean LLVM implement');
        }
        $context->registerFunction('__compiler_filter_parse_boolean_string', $fn);
    }
}
