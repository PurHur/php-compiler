<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Variable::toBool() for nested php-in-PHP JIT helpers (#12910 / #33687).
 *
 * `__value__readLong` has no TYPE_NATIVE_BOOL arm (returns 0) — #21892. Dispatch
 * JIT bool (tag 2) via {@see JitValueBox::readBoolByte}; keep trunc(readLong) for
 * other tags so existing NestedJIT call sites stay bit-compatible.
 */
final class VariableToBool implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('toBool() requires a Variable receiver');
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'var_tobool_cont');
        $ptr = JitValueBox::valuePtrFromVariable($context, $args[0]);
        $map = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $typeByte = $context->builder->load($context->builder->structGep($ptr, $map['type']));
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL & 0x7f, false)
        );

        $boolBlock = BasicBlockHelper::append($context, 'var_tobool_native_bool');
        $otherBlock = BasicBlockHelper::append($context, 'var_tobool_other');
        $done = BasicBlockHelper::append($context, 'var_tobool_done');
        $context->builder->branchIf($isBool, $boolBlock, $otherBlock);

        $context->builder->positionAtEnd($boolBlock);
        $boolByte = JitValueBox::readBoolByte($context, $ptr);
        $fromBool = $context->builder->icmp(
            Builder::INT_NE,
            $boolByte,
            $i8->constInt(0, false)
        );
        $boolEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($otherBlock);
        // Preserve prior trunc(readLong) semantics for non-bool tags.
        $long = $context->builder->call($context->lookupFunction('__value__readLong'), $ptr);
        $fromOther = $context->builder->trunc($long, $i1);
        $otherEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($fromBool, $boolEnd);
        $phi->addIncoming($fromOther, $otherEnd);

        return $phi;
    }
}
