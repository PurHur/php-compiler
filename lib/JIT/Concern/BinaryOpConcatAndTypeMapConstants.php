<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPTypes\Type;
use PHPCompiler\JIT\Variable;

/**
 * Binary-op, concat materialization, and Variable type-map constants (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code materializeConcatLeaves}
 * through {@code jitVariableTypeMapConstant} so the hub shrinks toward
 * split-TU iterability under the size-budget ratchet. Sibling of
 * CompileIncDecAndConcatFlatten (#36763), which owns chain flatten / inc-dec.
 *
 * php-src: Zend/zend_operators.c zend_binary_op / concat_function /
 * zend_compare; Zend/zend_hash.c numeric string keys — move-only Concern
 * extract; no new C ABI and no opcode/IR shape change.
 */
trait BinaryOpConcatAndTypeMapConstants
{
    /**
     * @param list<\PHPCfg\Operand> $leaves
     */
    private function materializeConcatLeaves(array $leaves, Block $block): Variable
    {
        if ([] === $leaves) {
            return new Variable(
                $this->context,
                Variable::TYPE_STRING,
                Variable::KIND_VALUE,
                $this->context->builder->load($this->context->constantStringFromString(''))
            );
        }
        $acc = null;
        foreach ($leaves as $leafOp) {
            if (!$this->context->hasVariableOp($leafOp)) {
                if ($leafOp instanceof Operand\Literal && \is_string($leafOp->value)) {
                    $next = new Variable(
                        $this->context,
                        Variable::TYPE_STRING,
                        Variable::KIND_VALUE,
                        $this->context->builder->load(
                            $this->context->constantStringFromString($leafOp->value)
                        )
                    );
                    $next->compileTimeString = $leafOp->value;
                } elseif ($leafOp instanceof Operand\Literal && \is_int($leafOp->value)) {
                    $i64 = $this->context->getTypeFromString('int64');
                    $next = new Variable(
                        $this->context,
                        Variable::TYPE_NATIVE_LONG,
                        Variable::KIND_VALUE,
                        $i64->constInt((int) $leafOp->value, true)
                    );
                } else {
                    $this->context->makeVariableFromOp(
                        JIT\BasicBlockHelper::parentFunction($this->context),
                        $this->context->builder->getInsertBlock(),
                        $block,
                        $leafOp
                    );
                    $next = $this->context->getVariableFromOp($leafOp);
                }
            } else {
                $next = $this->context->getVariableFromOp($leafOp);
            }
            if (null === $acc) {
                if (Variable::TYPE_NATIVE_LONG === $next->type) {
                    $acc = new Variable(
                        $this->context,
                        Variable::TYPE_STRING,
                        Variable::KIND_VALUE,
                        JIT\JitNativeString::fromLong(
                            $this->context,
                            $this->context->helper->loadValue($next)
                        )
                    );
                } else {
                    $acc = JIT\JitNativeString::coerce($this->context, $next, $leafOp);
                }
                continue;
            }
            $acc = $this->compileConcatIntoNewString($acc, $next, null, $leafOp);
        }

        return $acc;
    }

    /** Allocate a fresh native string holding left . right (php-src string concat semantics). */
    private function compileConcatIntoNewString(
        Variable $left,
        Variable $right,
        ?\PHPCfg\Operand $leftOp = null,
        ?\PHPCfg\Operand $rightOp = null
    ): Variable
    {
        $this->context->intrinsic->builder = $this->context->builder;
        $leftIsLong = Variable::TYPE_NATIVE_LONG === $left->type;
        $rightIsLong = Variable::TYPE_NATIVE_LONG === $right->type;
        if ($rightIsLong && !$leftIsLong) {
            return $this->compileConcatStringAndI64($left, $right, $leftOp, false);
        }
        if ($leftIsLong && !$rightIsLong) {
            return $this->compileConcatStringAndI64($right, $left, $rightOp, true);
        }
        $left = JIT\JitNativeString::coerce($this->context, $left, $leftOp);
        $right = JIT\JitNativeString::coerce($this->context, $right, $rightOp);
        $leftVar = $this->context->helper->loadValue($left);
        $rightVar = $this->context->helper->loadValue($right);
        $map = $this->context->structFieldMap['__string__'];
        $leftSize = $this->context->builder->load(
            $this->context->builder->structGep($leftVar, $map['length'])
        );
        $rightSize = $this->context->builder->load(
            $this->context->builder->structGep($rightVar, $map['length'])
        );
        $size = $this->context->builder->addNoUnsignedWrap(
            $leftSize,
            $this->context->builder->intCast($rightSize, $leftSize->typeOf())
        );
        $result = $this->context->builder->call(
            $this->context->lookupFunction('__string__alloc'),
            $size
        );
        $char = $this->context->builder->structGep($result, $map['value']);
        $leftChar = $this->context->builder->structGep($leftVar, $map['value']);
        $this->context->intrinsic->memcpy($char, $leftChar, $leftSize, false);
        $char = $this->context->builder->gep($char, $leftSize);
        $rightChar = $this->context->builder->structGep($rightVar, $map['value']);
        $this->context->intrinsic->memcpy($char, $rightChar, $rightSize, false);

        $var = new Variable(
            $this->context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $result
        );
        if (
            null !== ($left->compileTimeString ?? null)
            && null !== ($right->compileTimeString ?? null)
        ) {
            $var->compileTimeString = $left->compileTimeString.$right->compileTimeString;
        }

        return $var;
    }

    /**
     * string . int / int . string in one alloc (php-src concat + zend_print_long_to_buf).
     *
     * Avoids a heap temp for the decimal digits (#36386 / str-builder, template-render).
     */
    private function compileConcatStringAndI64(
        Variable $strSide,
        Variable $longSide,
        ?\PHPCfg\Operand $strOp,
        bool $longFirst
    ): Variable {
        $this->context->intrinsic->builder = $this->context->builder;
        $strSide = JIT\JitNativeString::coerce($this->context, $strSide, $strOp);
        $strVar = $this->context->helper->loadValue($strSide);
        $longVal = $this->context->helper->loadValue($longSide);
        [$digits, $digitLen] = JIT\JitNativeString::writeDecimalDigits($this->context, $longVal);
        $map = $this->context->structFieldMap['__string__'];
        $strLen = $this->context->builder->load(
            $this->context->builder->structGep($strVar, $map['length'])
        );
        $strBytes = $this->context->builder->structGep($strVar, $map['value']);
        $total = $this->context->builder->addNoUnsignedWrap(
            $strLen,
            $this->context->builder->intCast($digitLen, $strLen->typeOf())
        );
        $result = $this->context->builder->call(
            $this->context->lookupFunction('__string__alloc'),
            $total
        );
        $dest = $this->context->builder->structGep($result, $map['value']);
        if ($longFirst) {
            $this->context->intrinsic->memcpy($dest, $digits, $digitLen, false);
            $this->context->intrinsic->memcpy(
                $this->context->builder->gep($dest, $digitLen),
                $strBytes,
                $strLen,
                false
            );
        } else {
            $this->context->intrinsic->memcpy($dest, $strBytes, $strLen, false);
            $this->context->intrinsic->memcpy(
                $this->context->builder->gep($dest, $strLen),
                $digits,
                $digitLen,
                false
            );
        }
        $var = new Variable(
            $this->context,
            Variable::TYPE_STRING,
            Variable::KIND_VALUE,
            $result
        );
        $var->compileTimeString = null;

        return $var;
    }

    private function compileBinaryOp(OpCode $op, Variable $left, Variable $right): Variable
    {
        if ($this->isOrderedCompareOpcode($op->type)) {
            [$left, $right] = $this->materializeOrderedCompareNativeLongOperands($left, $right);
        }
        // VALUE×VALUE &|^ must go through Helper::binaryOp so string tags use
        // StringBitwiseNot::emitBinary (Zend bitwise_*_function). A prior
        // readLong-only short-circuit coerced "$a & $b" to int (#35312).
        return $this->context->helper->binaryOp($op, $left, $right);
    }

    private static function isOrderedCompareOpcode(int $opcodeType): bool
    {
        return OpCode::TYPE_SMALLER === $opcodeType
            || OpCode::TYPE_GREATER === $opcodeType
            || OpCode::TYPE_SMALLER_OR_EQUAL === $opcodeType
            || OpCode::TYPE_GREATER_OR_EQUAL === $opcodeType;
    }

    /**
     * User-function `$i < $len` must not use orderedNativeLongToValue on boxed
     * property temps — snapshot to i64 like `(int)$len` (#36018).
     *
     * @return array{0: Variable, 1: Variable}
     */
    private function materializeOrderedCompareNativeLongOperands(Variable $left, Variable $right): array
    {
        $block = $this->context->jitEnclosingBlock;
        if (null === $block || null === $block->func || $block->isMainScript()) {
            return [$left, $right];
        }
        if (Variable::TYPE_NATIVE_LONG === $left->type
            && Variable::TYPE_VALUE === $right->type
            && JIT\JitValueBox::isValueOperand($right)
        ) {
            $right = $this->coerceValueBoxToNativeLongAlloca($right);
        }
        if (Variable::TYPE_NATIVE_LONG === $right->type
            && Variable::TYPE_VALUE === $left->type
            && JIT\JitValueBox::isValueOperand($left)
        ) {
            $left = $this->coerceValueBoxToNativeLongAlloca($left);
        }

        return [$left, $right];
    }

    private function coerceValueBoxToNativeLongAlloca(Variable $var): Variable
    {
        if (null !== $var->objectPropertySlot) {
            $propType = $var->objectPropertyType ?? $var->type;
            if (Variable::TYPE_NATIVE_LONG === $propType) {
                return $this->snapshotNativeScalarPropertyRead($var, $propType);
            }
        }
        $long = ext\standard\JitZendScalarCast::emitIntCast($this->context, $var);
        $i64 = $this->context->getTypeFromString('int64');
        $slot = JIT\BasicBlockHelper::entryAlloca($this->context, $i64);
        $this->context->builder->store($long, $slot);
        $native = new Variable(
            $this->context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VARIABLE,
            $slot
        );
        $native->addref();
        $native->compileTimeLong = null;

        return $native;
    }

    private function jitVariableArrayClassConstant(string $constName): ?Variable
    {
        switch (strtolower($constName)) {
            case 'native_type_map':
                return $this->jitVariableNativeTypeMapConstant();
            case 'type_map':
                return $this->jitVariableTypeMapConstant();
            default:
                return null;
        }
    }

    private function jitArrayElementKeyVariable(Block $block, ?int $keyArg): ?Variable
    {
        if (null === $keyArg) {
            return null;
        }
        $intKey = $this->tryCompileTimeArrayLiteralIntKey($block, $keyArg);
        if (null !== $intKey) {
            return new Variable(
                $this->context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $this->context->constantFromInteger($intKey, 'int64')
            );
        }

        return $this->context->getVariableFromOp($block->getOperand($keyArg));
    }

    /**
     * Zend array-literal key: int keys and canonical numeric strings share one slot (#4151).
     */
    private function tryCompileTimeArrayLiteralIntKey(Block $block, int $keyArg): ?int
    {
        if (isset($block->constants[$keyArg])) {
            $const = $block->constants[$keyArg];
            if (VM\Variable::TYPE_INTEGER === $const->type) {
                return $const->toInt();
            }
            if (VM\Variable::TYPE_STRING === $const->type) {
                return VM\HashTable::tryIntFromNumericString($const->toString());
            }
            if (VM\Variable::TYPE_FLOAT === $const->type) {
                return $const->toInt();
            }
        }
        $op = $block->getOperand($keyArg);
        if ($op instanceof Operand\Literal) {
            if (is_int($op->value)) {
                return $op->value;
            }
            if (is_string($op->value)) {
                return VM\HashTable::tryIntFromNumericString($op->value);
            }
            if (is_float($op->value)) {
                return (int) $op->value;
            }
        }

        return null;
    }

    private function bumpNativeArrayNextFreeForExplicitIntKey(
        Variable $array,
        ?int $keyArg,
        Block $block
    ): void {
        if (null === $keyArg || 0 === ($array->type & Variable::IS_NATIVE_ARRAY)) {
            return;
        }
        $keyOp = $block->getOperand($keyArg);
        if (!$keyOp instanceof Operand\Literal || !is_int($keyOp->value)) {
            return;
        }
        $needed = $keyOp->value + 1;
        if ($needed > $array->nextFreeElement) {
            $array->nextFreeElement = $needed;
        }
    }

    private function jitVariableNativeTypeMapConstant(): Variable
    {
        $slot = JIT\BasicBlockHelper::entryAlloca(
            $this->context,
            $this->context->getTypeFromString('__hashtable__*')
        );
        $result = new Variable(
            $this->context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VARIABLE,
            $slot
        );
        JIT\HashTableHelper::initArray($this->context, $result);
        foreach (JIT\Variable::NATIVE_TYPE_MAP as $typeKey => $typeName) {
            $key = Variable::fromConstantInt($this->context, $typeKey);
            $lit = new Operand\Literal($typeName);
            $lit->type = Type::string();
            $element = Variable::fromLiteral($this->context, $lit);
            JIT\HashTableHelper::addElement($this->context, $result, $element, $key);
        }

        return $result;
    }

    private function jitVariableTypeMapConstant(): Variable
    {
        $slot = JIT\BasicBlockHelper::entryAlloca(
            $this->context,
            $this->context->getTypeFromString('__hashtable__*')
        );
        $result = new Variable(
            $this->context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VARIABLE,
            $slot
        );
        JIT\HashTableHelper::initArray($this->context, $result);
        foreach (JIT\Variable::TYPE_MAP as $typeKey => $typeValue) {
            $key = Variable::fromConstantInt($this->context, $typeKey);
            $element = Variable::fromConstantInt($this->context, $typeValue);
            JIT\HashTableHelper::addElement($this->context, $result, $element, $key);
        }

        return $result;
    }

}
