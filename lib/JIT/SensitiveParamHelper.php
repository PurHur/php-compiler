<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\VM\SensitiveParamSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * #[\SensitiveParameter] JIT lowering for debug_backtrace args (issue #3351, #4621).
 */
final class SensitiveParamHelper
{
    public static function classId(Context $context): int
    {
        return $context->type->object->lookup(SensitiveParamSupport::CLASS_NAME);
    }

    /** Empty SensitiveParameterValue marker object (Zend SensitiveParameterValue). */
    public static function createMarker(Context $context): Variable
    {
        $obj = $context->type->object->allocate(self::classId($context));

        return new Variable(
            $context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $obj
        );
    }

    /**
     * Packed call arguments for the enclosing frame, redacting sensitive params.
     */
    public static function buildArgsArray(Context $context, Block $block): Value
    {
        $argsHt = HashTableHelper::alloc($context);
        if ([] === $block->paramNames) {
            return $argsHt;
        }

        $sensitive = $block->paramSensitive;
        $sizeT = $context->getTypeFromString('size_t');
        $index = 0;
        foreach (array_keys($block->paramNames) as $paramIdx) {
            $slot = $context->constantFromInteger($index, 'size_t');
            ++$index;
            if (isset($sensitive[$paramIdx])) {
                HashTableHelper::setAtIndex($context, $argsHt, $slot, self::createMarker($context));

                continue;
            }
            $paramName = $block->paramNames[$paramIdx];
            $binding = VarFetchHelper::bindingByName($context, $block, $paramName);
            if (null === $binding) {
                continue;
            }
            HashTableHelper::setAtIndex($context, $argsHt, $slot, $binding);
        }

        return $argsHt;
    }

    public static function ignoreArgsBit(Context $context, ?Variable $optionsArg): Value
    {
        $i1 = $context->getTypeFromString('int1');
        if (null === $optionsArg) {
            return $i1->constInt(0, false);
        }

        $options = self::readOptionsLong($context, $optionsArg);
        $mask = $context->constantFromInteger(VmDebugBacktraceOptions::IGNORE_ARGS, 'int64');
        $masked = $context->builder->and($options, $mask);
        $zero = $context->getTypeFromString('int64')->constInt(0, false);

        return $context->builder->icmp(Builder::INT_NE, $masked, $zero);
    }

    private static function readOptionsLong(Context $context, Variable $optionsArg): Value
    {
        if (Variable::TYPE_NATIVE_LONG === $optionsArg->type) {
            return $context->helper->loadValue($optionsArg);
        }
        if (Variable::TYPE_VALUE === $optionsArg->type) {
            $valuePtr = Variable::KIND_VARIABLE === $optionsArg->kind
                ? JitValueBox::pointer($context, $optionsArg->value)
                : $optionsArg->value;

            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $valuePtr
            );
        }

        return $context->constantFromInteger(0, 'int64');
    }
}

/** Mirrors {@see \PHPCompiler\ext\standard\VmDebugBacktrace} flags without ext/ import cycles. */
final class VmDebugBacktraceOptions
{
    public const IGNORE_ARGS = 2;
}
