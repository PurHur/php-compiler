<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\filter\JitFilter;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * AOT filter_var() with FILTER_REQUIRE_ARRAY — map filter over array elements (#29047).
 *
 * php-src: ext/filter/filter.c — php_zval_filter_recursive (REQUIRE_ARRAY level-local).
 */
final class FilterVarRequireArrayLlvm
{
    public static function filter(
        Context $context,
        JITVariable $value,
        int $filterId,
        ?JITVariable $optionsArg
    ): Value {
        if (self::isDefinitelyNonArray($value)) {
            return JitFilter::boxedFalse($context);
        }
        if (self::isDefinitelyArray($value)) {
            return self::mapAndBox($context, $value, $filterId);
        }

        // Boxed runtime value: branch on __value__ hashtable tag.
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $value);
        $isHt = self::valuePtrIsHashtable($context, $valuePtr);
        $mapBlock = BasicBlockHelper::append($context, 'fvra_map');
        $failBlock = BasicBlockHelper::append($context, 'fvra_fail');
        $mergeBlock = BasicBlockHelper::append($context, 'fvra_done');
        $context->builder->branchIf($isHt, $mapBlock, $failBlock);

        $context->builder->positionAtEnd($mapBlock);
        $mapped = self::mapAndBox($context, $value, $filterId);
        $mapTail = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($failBlock);
        $falseResult = JitFilter::boxedFalse($context);
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($mapped->typeOf());
        $phi->addIncoming($mapped, $mapTail);
        $phi->addIncoming($falseResult, $failTail);

        return $phi;
    }

    private static function mapAndBox(Context $context, JITVariable $value, int $filterId): Value
    {
        $srcHt = ArrayBuiltinHelper::loadHashTable($context, $value);
        $filteredHt = FilterVarArrayLlvm::mapByFilterId($context, $srcHt, $filterId);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $filteredHt
        );

        return $ptr;
    }

    private static function isDefinitelyArray(JITVariable $value): bool
    {
        if (\is_array($value->compileTimeAssoc)) {
            return true;
        }
        if ($value->valueBoxHashtable) {
            return true;
        }

        return JITVariable::TYPE_HASHTABLE === $value->type
            || ArrayBuiltinHelper::isNativeArray($value->type);
    }

    private static function isDefinitelyNonArray(JITVariable $value): bool
    {
        if (self::isDefinitelyArray($value)) {
            return false;
        }

        return JITVariable::TYPE_STRING === $value->type
            || JITVariable::TYPE_NATIVE_LONG === $value->type
            || JITVariable::TYPE_NATIVE_DOUBLE === $value->type
            || JITVariable::TYPE_NATIVE_BOOL === $value->type
            || JITVariable::TYPE_NULL === $value->type;
    }

    private static function valuePtrIsHashtable(Context $context, Value $ptr): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $typeByte = $context->builder->load(
            $context->builder->structGep($ptr, $context->structFieldMap['__value__']['type'])
        );
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        return $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_HASHTABLE & 0x7f, false)
        );
    }
}
