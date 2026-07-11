<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\Block;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Builtin\MbNumericEntity;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable as VMVariable;
use PHPCfg\Operand;
use PHPLLVM\Value;

/**
 * Compile-time folding and runtime lowering for mb_encode_numericentity() / mb_decode_numericentity() (#7237).
 */
final class JitMbNumericEntity
{
    /**
     * @param JITVariable[] $args
     */
    public static function invokeEncodeRuntime(Context $context, array $args): Value
    {
        $folded = self::tryEncodeCompileTimeFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('mb_encode_numericentity() expects 2 to 4 arguments in this compiler build');
        }

        $str = JitStringBuiltinArg::lower($context, $args[0], 'mb_encode_numericentity', 0, 'string');
        $mapScalars = self::lowerConvMapScalars($context, $args[1]);
        $encoding = self::runtimeEncodingLiteral($args, 2, 'mb_encode_numericentity');
        $isHexI8 = self::runtimeIsHexI8($context, $args, 3);

        MbNumericEntity::ensureLinked($context);
        $encPtr = $context->builder->load($context->constantStringFromString($encoding));

        return $context->builder->call(
            $context->lookupFunction('__compiler_mb_encode_numericentity4'),
            $str,
            $mapScalars[0],
            $mapScalars[1],
            $mapScalars[2],
            $mapScalars[3],
            $encPtr,
            $isHexI8
        );
    }

    /**
     * @return list<Value>
     */
    private static function lowerConvMapScalars(Context $context, JITVariable $arg): array
    {
        if (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            $elemType = $arg->type & ~JITVariable::IS_NATIVE_ARRAY;
            if (JITVariable::TYPE_NATIVE_LONG === $elemType && 4 === $arg->nextFreeElement) {
                $out = [];
                for ($i = 0; $i < 4; ++$i) {
                    $elem = $arg->dimFetch(JITVariable::fromConstantInt($context, $i));
                    if (JITVariable::TYPE_NATIVE_LONG !== $elem->type || JITVariable::KIND_VALUE !== $elem->kind) {
                        break;
                    }
                    $const = $elem->value;
                    if (!($const instanceof Value && $const->isConstant())) {
                        break;
                    }
                    $out[] = $const;
                }
                if (4 === \count($out)) {
                    return $out;
                }
            }
        }

        $mapHt = ArrayBuiltinHelper::isNativeArray($arg->type)
            ? ArrayBuiltinHelper::nativeListToHashTable($context, $arg)
            : ArrayBuiltinHelper::loadHashTable($context, $arg);
        $readLong = $context->lookupFunction('__hashtable__readLongAt');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $out = [];
        for ($i = 0; $i < 4; ++$i) {
            $out[] = $context->builder->call(
                $readLong,
                $mapHt,
                $context->builder->intCast($i64->constInt($i, false), $sizeT)
            );
        }

        return $out;
    }

    /**
     * @param JITVariable[] $args
     */
    public static function invokeDecodeRuntime(Context $context, array $args): Value
    {
        $folded = self::tryDecodeCompileTimeFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('mb_decode_numericentity() expects 2 to 3 arguments in this compiler build');
        }

        $str = JitStringBuiltinArg::lower($context, $args[0], 'mb_decode_numericentity', 0, 'string');
        $mapScalars = self::lowerConvMapScalars($context, $args[1]);
        $encoding = self::runtimeEncodingLiteral($args, 2, 'mb_decode_numericentity');

        MbNumericEntity::ensureLinked($context);
        $encPtr = $context->builder->load($context->constantStringFromString($encoding));

        return $context->builder->call(
            $context->lookupFunction('__compiler_mb_decode_numericentity4'),
            $str,
            $mapScalars[0],
            $mapScalars[1],
            $mapScalars[2],
            $mapScalars[3],
            $encPtr
        );
    }

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

        return $context->builder->load($context->constantStringFromString(
            VmMbstring::encodeNumericEntity($str, $convmap, $encoding ?? 'UTF-8', $isHex)
        ));
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

        return $context->builder->load($context->constantStringFromString(
            VmMbstring::decodeNumericEntity($str, $convmap, $encoding ?? 'UTF-8')
        ));
    }

    /**
     * @return list<int>|null
     */
    private static function compileTimeConvMap(Context $context, JITVariable $var): ?array
    {
        if (0 !== ($var->type & JITVariable::IS_NATIVE_ARRAY)) {
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

        $convmapOperand = $context->jitMbNumericEntityConvmapOperand;
        $block = $context->jitEnclosingBlock;
        if (null !== $block && $convmapOperand instanceof Operand) {
            $fromBlock = self::compileTimeConvMapFromBlock($block, $convmapOperand);
            if (null !== $fromBlock) {
                return $fromBlock;
            }
        }

        return null;
    }

    /**
     * @return list<int>|null
     */
    private static function compileTimeConvMapFromBlock(Block $block, Operand $operand): ?array
    {
        $slot = $block->getVarSlot($operand, true);
        $elements = [];
        $foundInit = false;
        foreach ($block->opCodes as $op) {
            if ($op->arg1 !== $slot) {
                continue;
            }
            if (OpCode::TYPE_INIT_ARRAY !== $op->type && OpCode::TYPE_ADD_ARRAY_ELEMENT !== $op->type) {
                continue;
            }
            $foundInit = true;
            $valueSlot = $op->arg2;
            if (null === $valueSlot || !isset($block->constants[$valueSlot])) {
                return null;
            }
            $const = $block->constants[$valueSlot];
            if (VMVariable::TYPE_INTEGER !== $const->type) {
                return null;
            }
            $elements[] = $const->toInt();
        }
        if (!$foundInit || 0 === \count($elements) || 0 !== (\count($elements) % 4)) {
            return null;
        }

        return $elements;
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

    /**
     * @param JITVariable[] $args
     */
    private static function runtimeEncodingLiteral(array $args, int $index, string $function): string
    {
        $encoding = self::compileTimeEncoding($args, $index);
        if (null === $encoding) {
            throw new \LogicException(
                $function.'() JIT requires compile-time encoding literal in this compiler build'
            );
        }
        VmMbstring::resolveNumericEntityEncoding($encoding, $function, $index);

        return $encoding;
    }

    /**
     * @param JITVariable[] $args
     */
    private static function runtimeIsHexI8(Context $context, array $args, int $index): Value
    {
        $i8 = $context->getTypeFromString('int8');
        if (!isset($args[$index])) {
            return $i8->constInt(0, false);
        }
        $boolFold = self::compileTimeBool($args, $index);
        if (null !== $boolFold) {
            return $i8->constInt($boolFold ? 1 : 0, false);
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $args[$index]->type && JITVariable::KIND_VALUE === $args[$index]->kind) {
            return $context->builder->zExt($context->helper->loadValue($args[$index]), $i8);
        }

        throw new \LogicException(
            'mb_encode_numericentity() is_hex must be a boolean in this compiler build'
        );
    }
}
