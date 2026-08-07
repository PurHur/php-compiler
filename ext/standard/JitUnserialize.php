<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringUnserialize;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitUnserialize
{
    public static function decodeRuntime(Context $context, JITVariable $payload): Value
    {
        StringUnserialize::ensureLinked($context);
        // Soft-null DEP+coerce on 8.4 — Zend Z_PARAM_STR (#21223; reverts #18840 TypeError).
        $payloadString = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $payload,
            'unserialize',
            0,
            'data'
        );

        return self::decodeRuntimeString($context, $payloadString);
    }

    /**
     * @param array<string, mixed> $options compile-time options (allowed_classes, max_depth)
     */
    public static function decodeRuntimeWithOptions(
        Context $context,
        JITVariable $payload,
        array $options
    ): Value {
        $literal = JitStringArg::compileTimeLiteral($payload);
        if (null !== $literal) {
            $decoded = VmUnserializeFormat::decodePayload($literal, $options);
            if (false === $decoded) {
                return JitJsonDecode::materializeScalar($context, false);
            }
            if (null === $decoded) {
                return JitJsonDecode::materializeNull($context);
            }
            if (\is_bool($decoded)) {
                return JitJsonDecode::materializeScalar($context, $decoded);
            }
            if (\is_int($decoded)) {
                return JitJsonDecode::materializeScalar($context, $decoded);
            }
            if (\is_string($decoded)) {
                return JitJsonDecode::materializeScalar($context, $decoded);
            }
            if (\is_array($decoded)) {
                return JitJsonDecode::materializeArray($context, $decoded);
            }

            throw new \LogicException('unserialize() result type not supported in this compiler build');
        }

        if ([] === $options || (1 === \count($options) && \array_key_exists('allowed_classes', $options) && true === $options['allowed_classes'])) {
            return self::decodeRuntime($context, $payload);
        }

        throw new \LogicException('unserialize() runtime payload with options not supported for JIT in this compiler build');
    }

    public static function decodeRuntimeString(Context $context, Value $payloadString): Value
    {
        StringUnserialize::ensureLinked($context);
        $fn = BasicBlockHelper::parentFunction($context);
        $i64 = $context->getTypeFromString('int64');
        $valuePtr = $context->getTypeFromString('__value__*');
        $strMap = $context->structFieldMap['__string__'];

        $bbObj = $fn->appendBasicBlock('unser_runtime_obj');
        $bbInt = $fn->appendBasicBlock('unser_runtime_int');
        $bbMerge = $fn->appendBasicBlock('unser_runtime_merge');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $valuePtr);

        $strLen = $context->builder->load(
            $context->builder->structGep($payloadString, $strMap['length'])
        );
        $bbPeek = $fn->appendBasicBlock('unser_runtime_peek');
        $empty = $context->builder->icmp(Builder::INT_EQ, $strLen, $i64->constInt(0, false));
        $context->builder->branchIf($empty, $bbInt, $bbPeek);

        $context->builder->positionAtEnd($bbPeek);
        $bytesPtr = $context->builder->structGep($payloadString, $strMap['value']);
        $firstByte = $context->builder->load($bytesPtr);
        $firstExt = $context->builder->zExt($firstByte, $i64);
        $isObj = $context->builder->icmp(
            Builder::INT_EQ,
            $firstExt,
            $i64->constInt(\ord('O'), false)
        );
        $context->builder->branchIf($isObj, $bbObj, $bbInt);

        $context->builder->positionAtEnd($bbObj);
        $objVal = StringUnserialize::emitObjectDecodeRuntime($context, $payloadString);
        $context->builder->store($objVal, $resultSlot);
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbInt);
        // __compiler_unserialize returns __value__* (ArrayPop #12647 / #20785 ABI).
        $intVal = $context->builder->call(
            $context->lookupFunction('__compiler_unserialize'),
            $payloadString
        );
        $context->builder->store($intVal, $resultSlot);
        $context->builder->branch($bbMerge);

        $context->builder->positionAtEnd($bbMerge);

        return $context->builder->load($resultSlot);
    }
}
