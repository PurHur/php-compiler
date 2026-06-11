<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Compile-time folding for mb_encode_numericentity() / mb_decode_numericentity() (#7237).
 */
final class JitMbNumericEntity
{
    /**
     * @param JITVariable[] $args
     */
    public static function tryEncodeCompileTimeFold(Context $context, array $args): ?Value
    {
        if (\count($args) < 2 || \count($args) > 4) {
            return null;
        }
        $str = JitStringArg::compileTimeLiteral($args[0]);
        $convmap = self::compileTimeConvMap($context, $args[1]);
        if (null === $str || null === $convmap) {
            return null;
        }
        $encoding = self::compileTimeEncoding($args, 2);
        if (null === $encoding && isset($args[2])) {
            return null;
        }
        $isHex = false;
        if (isset($args[3])) {
            $boolFold = self::compileTimeBool($args, 3);
            if (null === $boolFold) {
                return null;
            }
            $isHex = $boolFold;
        }

        return $context->constantFromString(
            VmMbstring::encodeNumericEntity($str, $convmap, $encoding ?? 'UTF-8', $isHex)
        );
    }

    /**
     * @param JITVariable[] $args
     */
    public static function tryDecodeCompileTimeFold(Context $context, array $args): ?Value
    {
        if (\count($args) < 2 || \count($args) > 3) {
            return null;
        }
        $str = JitStringArg::compileTimeLiteral($args[0]);
        $convmap = self::compileTimeConvMap($context, $args[1]);
        if (null === $str || null === $convmap) {
            return null;
        }
        $encoding = self::compileTimeEncoding($args, 2);
        if (null === $encoding && isset($args[2])) {
            return null;
        }

        return $context->constantFromString(
            VmMbstring::decodeNumericEntity($str, $convmap, $encoding ?? 'UTF-8')
        );
    }

    /**
     * @return list<int>|null
     */
    private static function compileTimeConvMap(Context $context, JITVariable $var): ?array
    {
        if (0 === ($var->type & JITVariable::IS_NATIVE_ARRAY)) {
            return null;
        }
        $elemType = $var->type & ~JITVariable::IS_NATIVE_ARRAY;
        if (JITVariable::TYPE_NATIVE_LONG !== $elemType) {
            return null;
        }
        $count = $var->nextFreeElement;
        if (0 === $count || 0 !== ($count % 4)) {
            return null;
        }
        $out = [];
        for ($i = 0; $i < $count; ++$i) {
            $elem = $var->dimFetch(JITVariable::fromConstantInt($context, $i));
            if (JITVariable::TYPE_NATIVE_LONG !== $elem->type || JITVariable::KIND_VALUE !== $elem->kind) {
                return null;
            }
            $const = $elem->value;
            if (!($const instanceof Value && $const->isConstant())) {
                return null;
            }
            $out[] = (int) $const->constInt();
        }

        return $out;
    }

    /**
     * @param JITVariable[] $args
     */
    private static function compileTimeBool(array $args, int $index): ?bool
    {
        if (!isset($args[$index])) {
            return null;
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
        if (JITVariable::TYPE_NULL === $args[$index]->type) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_STRING !== $args[$index]->type) {
            return null;
        }

        return $args[$index]->compileTimeString ?? null;
    }
}
