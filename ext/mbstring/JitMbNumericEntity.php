<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbNumericEntity;
use PHPCompiler\JIT\CallUnpackHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\OpCode;
use PHPLLVM\Value;

/**
 * Compile-time folding and runtime lowering for mb_encode_numericentity() / mb_decode_numericentity()
 * (#7237, #35210 runtime encoding).
 *
 * Compile-time fold for string literals; runtime haystack/encoding via NestedJIT
 * {@see MbNumericEntityJitHelper} (peer {@see JitMbTrim} / #35199).
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
        $isHexI64 = self::runtimeIsHexI64($context, $args, 3);

        // Link NestedJIT helpers before encoding lower — NestedJIT can invalidate prior IR (#34270 / #35199).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbNumericEntity::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_encode_numericentity_runtime');

        [$encPtr, $needsAssert] = self::encodingPtr($context, $args, $argc, 'mb_encode_numericentity');
        if ($needsAssert) {
            $fnName = $context->builder->load($context->constantStringFromString('mb_encode_numericentity'));
            $context->builder->call(
                MbNumericEntity::assertEncodingHelper($context),
                $encPtr,
                $fnName
            );
        }

        // Runtime int map ABI — raw call mismatches NestedJIT __value__* formals (#35254 / peer #34278).
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            MbNumericEntity::encode4Helper($context),
            [$str, $mapScalars[0], $mapScalars[1], $mapScalars[2], $mapScalars[3], $encPtr, $isHexI64]
        );
        $resultStr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);

        return self::materializeOwnedString($context, $resultStr);
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

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbNumericEntity::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_decode_numericentity_runtime');

        [$encPtr, $needsAssert] = self::encodingPtr($context, $args, $argc, 'mb_decode_numericentity');
        if ($needsAssert) {
            $fnName = $context->builder->load($context->constantStringFromString('mb_decode_numericentity'));
            $context->builder->call(
                MbNumericEntity::assertEncodingHelper($context),
                $encPtr,
                $fnName
            );
        }

        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            MbNumericEntity::decode4Helper($context),
            [$str, $mapScalars[0], $mapScalars[1], $mapScalars[2], $mapScalars[3], $encPtr]
        );
        $resultStr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);

        return self::materializeOwnedString($context, $resultStr);
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
     * Literal UTF-8/ASCII → constant string (no assert); otherwise NestedJIT encoding + assert (#35210).
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
            $canonical = self::canonicalNumericEntityEncoding($encodingLit);
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

    private static function canonicalNumericEntityEncoding(string $encoding): ?string
    {
        $upper = \strtoupper($encoding);
        if ('UTF-8' === $upper || 'UTF8' === $upper) {
            return 'UTF-8';
        }
        if ('ASCII' === $upper || 'US-ASCII' === $upper) {
            return 'ASCII';
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

    /**
     * @param list<Operand|null> $operands
     * @param JITVariable[]      $args
     */
    public static function tryEncodeCompileTimeFoldFromCallSite(
        Context $context,
        \PHPCompiler\Block $block,
        array $operands,
        array $args
    ): ?Value {
        if (\count($args) < 2 || \count($args) > 4 || !isset($operands[1])) {
            return null;
        }
        $savedOperand = $context->jitMbNumericEntityConvmapOperand;
        $savedBlock = $context->jitMbNumericEntityConvmapBlock;
        $context->jitMbNumericEntityConvmapOperand = $operands[1];
        $context->jitMbNumericEntityConvmapBlock = $block;
        try {
            return self::tryEncodeCompileTimeFold($context, $args);
        } finally {
            $context->jitMbNumericEntityConvmapOperand = $savedOperand;
            $context->jitMbNumericEntityConvmapBlock = $savedBlock;
        }
    }

    /**
     * @param list<Operand|null> $operands
     * @param JITVariable[]      $args
     */
    public static function tryDecodeCompileTimeFoldFromCallSite(
        Context $context,
        \PHPCompiler\Block $block,
        array $operands,
        array $args
    ): ?Value {
        if (\count($args) < 2 || \count($args) > 3 || !isset($operands[1])) {
            return null;
        }
        $savedOperand = $context->jitMbNumericEntityConvmapOperand;
        $savedBlock = $context->jitMbNumericEntityConvmapBlock;
        $context->jitMbNumericEntityConvmapOperand = $operands[1];
        $context->jitMbNumericEntityConvmapBlock = $block;
        try {
            return self::tryDecodeCompileTimeFold($context, $args);
        } finally {
            $context->jitMbNumericEntityConvmapOperand = $savedOperand;
            $context->jitMbNumericEntityConvmapBlock = $savedBlock;
        }
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
        $convmap = self::compileTimeConvMap($context, $args[1], 'mb_encode_numericentity');
        if (null === $str || null === $convmap) {
            return null;
        }
        $encoding = self::compileTimeEncoding($args, 2);
        if (null === $encoding && isset($args[2])) {
            return null;
        }
        // Unknown / unsupported encoding → runtime NestedJIT assert (catchable ValueError) (#35210).
        if (null === self::canonicalNumericEntityEncoding($encoding ?? 'UTF-8')) {
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

        return self::materializeString(
            $context,
            VmMbstring::encodeNumericEntity($str, $convmap, self::canonicalNumericEntityEncoding($encoding ?? 'UTF-8') ?? 'UTF-8', $isHex)
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
        $convmap = self::compileTimeConvMap($context, $args[1], 'mb_decode_numericentity');
        if (null === $str || null === $convmap) {
            return null;
        }
        $encoding = self::compileTimeEncoding($args, 2);
        if (null === $encoding && isset($args[2])) {
            return null;
        }
        if (null === self::canonicalNumericEntityEncoding($encoding ?? 'UTF-8')) {
            return null;
        }

        return self::materializeString(
            $context,
            VmMbstring::decodeNumericEntity(
                $str,
                $convmap,
                self::canonicalNumericEntityEncoding($encoding ?? 'UTF-8') ?? 'UTF-8'
            )
        );
    }

    /**
     * @return list<int>|null
     */
    private static function compileTimeConvMapFromContextOperand(Context $context, string $function): ?array
    {
        $operand = $context->jitMbNumericEntityConvmapOperand;
        $block = $context->jitMbNumericEntityConvmapBlock ?? $context->jitEnclosingBlock;
        if (null === $operand || null === $block) {
            return null;
        }
        $slot = $block->slotForOperand($operand);
        if (null !== $slot) {
            $fromSlot = self::compileTimeConvMapFromBlockSlot($block, $slot, $function);
            if (null !== $fromSlot) {
                return $fromSlot;
            }
        }
        $vmArray = CallUnpackHelper::tryCompileTimeArrayFromOperand($block, $operand);
        if (null === $vmArray) {
            return null;
        }
        try {
            return VmMbstring::coerceConvMapArg($vmArray, $function);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Append-style `[int, ...]` literals — CallUnpackCompileTime rejects null keys (#18035).
     *
     * @return list<int>|null
     */
    private static function compileTimeConvMapFromBlockSlot(
        \PHPCompiler\Block $block,
        int $slot,
        string $function
    ): ?array {
        $foundInit = false;
        $elements = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARRAY_SPREAD === $op->type && $op->arg1 === $slot) {
                return null;
            }
            if (OpCode::TYPE_INIT_ARRAY === $op->type && $op->arg1 === $slot) {
                $foundInit = true;
                if (null !== $op->arg2) {
                    $value = self::compileTimeIntFromBlockSlot($block, (int) $op->arg2);
                    if (null === $value) {
                        return null;
                    }
                    $elements[] = $value;
                }
                continue;
            }
            if (OpCode::TYPE_ADD_ARRAY_ELEMENT === $op->type && $op->arg1 === $slot) {
                $value = self::compileTimeIntFromBlockSlot($block, (int) $op->arg2);
                if (null === $value) {
                    return null;
                }
                $elements[] = $value;
            }
        }
        if (!$foundInit || [] === $elements) {
            return null;
        }
        try {
            return VmMbstring::validateConvMapElements($elements, $function);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function compileTimeIntFromBlockSlot(\PHPCompiler\Block $block, int $valueSlot): ?int
    {
        if (!isset($block->constants[$valueSlot])) {
            return null;
        }
        $const = $block->constants[$valueSlot];
        if (\PHPCompiler\VM\Variable::TYPE_INTEGER !== $const->type) {
            return null;
        }

        return $const->toInt();
    }

    /**
     * @return list<int>|null
     */
    private static function compileTimeConvMap(Context $context, JITVariable $var, string $function): ?array
    {
        $fromOperand = self::compileTimeConvMapFromContextOperand($context, $function);
        if (null !== $fromOperand) {
            return $fromOperand;
        }

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
        if (\is_array($var->compileTimeArray ?? null)) {
            $out = [];
            foreach ($var->compileTimeArray as $elem) {
                if (JITVariable::TYPE_NATIVE_LONG !== $elem->type || JITVariable::KIND_VALUE !== $elem->kind) {
                    return null;
                }
                $const = $elem->value;
                if (!($const instanceof Value && $const->isConstant())) {
                    return null;
                }
                $out[] = (int) $const->constInt();
            }
            if (0 === \count($out) || 0 !== (\count($out) % 4)) {
                return null;
            }

            return $out;
        }

        return null;
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
    private static function runtimeIsHexI64(Context $context, array $args, int $index): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if (!isset($args[$index])) {
            return $i64->constInt(0, false);
        }
        $boolFold = self::compileTimeBool($args, $index);
        if (null !== $boolFold) {
            return $i64->constInt($boolFold ? 1 : 0, false);
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $args[$index]->type && JITVariable::KIND_VALUE === $args[$index]->kind) {
            return $context->builder->zExt($context->helper->loadValue($args[$index]), $i64);
        }

        throw new \LogicException(
            'mb_encode_numericentity() is_hex must be a boolean in this compiler build'
        );
    }
}
