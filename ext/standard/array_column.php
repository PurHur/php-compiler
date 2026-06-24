<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitArrayColumnArg;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_column() for a list of associative arrays (ext/standard/array.c php_array_column subset).
 */
final class array_column extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('array_column() requires two or three arguments in this compiler build');
        }
        $ht = VmArray::requireArrayParam(
            $frame->calledArgs[0]->resolveIndirect(),
            'array_column',
            1,
            'array'
        );
        $column = $frame->calledArgs[1]->resolveIndirect();
        $indexKeySpec = 3 === $argc ? $frame->calledArgs[2]->resolveIndirect() : null;
        if (null === $frame->returnVar) {
            return;
        }
        $columnField = null;
        if (Variable::TYPE_NULL !== $column->type) {
            $columnField = VmArrayColumnArg::requireStrIntArg($column, 'array_column', 1, 'column_key');
        }
        $indexField = null;
        if (null !== $indexKeySpec && Variable::TYPE_NULL !== $indexKeySpec->type) {
            $indexField = VmArrayColumnArg::requireStrIntArg($indexKeySpec, 'array_column', 2, 'index_key');
        }

        $out = new HashTable();
        if (null === $columnField) {
            foreach ($ht->iterate(true) as $rowVar) {
                $row = $rowVar->resolveIndirect();
                if (Variable::TYPE_ARRAY !== $row->type && Variable::TYPE_OBJECT !== $row->type) {
                    $stored = new Variable();
                    $stored->copyFrom($row);
                    if (null !== $indexField) {
                        $indexVal = $this->readColumnFromRow($row, $indexField);
                        if (null === $indexVal) {
                            $out->append($stored);
                            continue;
                        }
                        $this->storeAtKey($out, $indexVal, $stored);
                        continue;
                    }
                    $out->append($stored);
                    continue;
                }
                $stored = new Variable();
                $stored->copyFrom($row);
                if (null !== $indexField) {
                    $indexVal = $this->readColumnFromRow($row, $indexField);
                    if (null === $indexVal) {
                        continue;
                    }
                    $this->storeAtKey($out, $indexVal, $stored);
                    continue;
                }
                $out->append($stored);
            }
            $frame->returnVar->array($out);

            return;
        }
        foreach ($ht->iterate(true) as $rowVar) {
            $row = $rowVar->resolveIndirect();
            if (null !== $indexField) {
                $indexVal = $this->readColumnFromRow($row, $indexField);
                $columnVal = $this->readColumnFromRow($row, $columnField);
                if (null === $indexVal || null === $columnVal) {
                    continue;
                }
                $stored = new Variable();
                $stored->copyFrom($columnVal);
                $this->storeAtKey($out, $indexVal, $stored);
                continue;
            }
            $columnVal = $this->readColumnFromRow($row, $columnField);
            if (null === $columnVal) {
                continue;
            }
            $stored = new Variable();
            $stored->copyFrom($columnVal);
            $out->append($stored);
        }
        $frame->returnVar->array($out);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('array_column() requires two or three arguments in this compiler build');
        }
        JitArrayElem::requireArrayParam($context, $args[0], 'array_column', 1, 'array');
        if (!JitArrayColumnArg::guardStrIntNullOperand($context, $args[1], 'array_column', 1, 'column_key')) {
            return HashTableHelper::alloc($context);
        }
        if (JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant) {
            if (2 === $argc) {
                return ArrayBuiltinHelper::buildColumnArrayNullColumn($context, $args[0]);
            }
            if (JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant) {
                return ArrayBuiltinHelper::buildColumnArrayNullColumn($context, $args[0]);
            }
            if (!JitArrayColumnArg::guardStrIntNullOperand($context, $args[2], 'array_column', 2, 'index_key')) {
                return HashTableHelper::alloc($context);
            }

            return $this->lowerNullColumnWithIndex($context, $args[0], $args[2]);
        }
        if (JITVariable::TYPE_VALUE === $args[1]->type) {
            if (2 === $argc) {
                return ArrayBuiltinHelper::buildColumnArrayWithRuntimeColumnKey($context, $args[0], $args[1]);
            }
            if (JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant) {
                return ArrayBuiltinHelper::buildColumnArrayWithRuntimeColumnKey($context, $args[0], $args[1]);
            }
            if (!JitArrayColumnArg::guardStrIntNullOperand($context, $args[2], 'array_column', 2, 'index_key')) {
                return HashTableHelper::alloc($context);
            }
            if (null !== JitStringArg::compileTimeLiteral($args[2])) {
                $indexKey = $this->jitString($context, $args[2], 'array_column() index_key');

                return ArrayBuiltinHelper::buildColumnArrayWithRuntimeColumnKeyAndIndex(
                    $context,
                    $args[0],
                    $args[1],
                    $indexKey
                );
            }
            if (JITVariable::TYPE_OBJECT === $args[2]->type) {
                JitArrayColumnArg::emitStrIntNullTypeErrorAndAbort(
                    $context,
                    'array_column',
                    2,
                    'index_key',
                    JitOperandTypeLabel::givenLabel($context, $args[2])
                );

                return HashTableHelper::alloc($context);
            }
            if (JITVariable::TYPE_VALUE === $args[2]->type) {
                return ArrayBuiltinHelper::buildColumnArrayWithRuntimeColumnKeyAndRuntimeIndex(
                    $context,
                    $args[0],
                    $args[1],
                    $args[2]
                );
            }
            throw new \LogicException(
                'array_column() index_key must be a string or integer in this compiler build'
            );
        }
        if (null === JitStringArg::compileTimeLiteral($args[1])) {
            if (JITVariable::TYPE_OBJECT === $args[1]->type) {
                JitArrayColumnArg::emitStrIntNullTypeErrorAndAbort(
                    $context,
                    'array_column',
                    1,
                    'column_key',
                    JitOperandTypeLabel::givenLabel($context, $args[1])
                );

                return HashTableHelper::alloc($context);
            }
            throw new \LogicException(
                'array_column() column key must be a string or integer in this compiler build'
            );
        }
        $columnKey = $this->jitString($context, $args[1], 'array_column() column key');
        if (2 === $argc) {
            return ArrayBuiltinHelper::buildColumnArray($context, $args[0], $columnKey);
        }
        if (JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant) {
            return ArrayBuiltinHelper::buildColumnArray($context, $args[0], $columnKey);
        }
        if (!JitArrayColumnArg::guardStrIntNullOperand($context, $args[2], 'array_column', 2, 'index_key')) {
            return HashTableHelper::alloc($context);
        }

        return $this->lowerColumnWithIndex($context, $args[0], $columnKey, $args[2]);
    }

    private function lowerNullColumnWithIndex(Context $context, JITVariable $array, JITVariable $indexKey): Value
    {
        if (null !== JitStringArg::compileTimeLiteral($indexKey)) {
            $indexKeyStr = $this->jitString($context, $indexKey, 'array_column() index_key');

            return ArrayBuiltinHelper::buildColumnArrayNullColumnWithIndex($context, $array, $indexKeyStr);
        }
        if (JITVariable::TYPE_OBJECT === $indexKey->type) {
            JitArrayColumnArg::emitStrIntNullTypeErrorAndAbort(
                $context,
                'array_column',
                2,
                'index_key',
                JitOperandTypeLabel::givenLabel($context, $indexKey)
            );

            return HashTableHelper::alloc($context);
        }
        if (JITVariable::TYPE_VALUE === $indexKey->type) {
            return ArrayBuiltinHelper::buildColumnArrayNullColumnWithRuntimeIndex($context, $array, $indexKey);
        }
        throw new \LogicException(
            'array_column() index_key must be a string or integer in this compiler build'
        );
    }

    private function lowerColumnWithIndex(
        Context $context,
        JITVariable $array,
        Value $columnKey,
        JITVariable $indexKey
    ): Value {
        if (null !== JitStringArg::compileTimeLiteral($indexKey)) {
            $indexKeyStr = $this->jitString($context, $indexKey, 'array_column() index_key');

            return ArrayBuiltinHelper::buildColumnArrayWithIndex($context, $array, $columnKey, $indexKeyStr);
        }
        if (JITVariable::TYPE_OBJECT === $indexKey->type) {
            JitArrayColumnArg::emitStrIntNullTypeErrorAndAbort(
                $context,
                'array_column',
                2,
                'index_key',
                JitOperandTypeLabel::givenLabel($context, $indexKey)
            );

            return HashTableHelper::alloc($context);
        }
        if (JITVariable::TYPE_VALUE === $indexKey->type) {
            return ArrayBuiltinHelper::buildColumnArrayWithRuntimeIndexKey($context, $array, $columnKey, $indexKey);
        }
        throw new \LogicException(
            'array_column() index_key must be a string or integer in this compiler build'
        );
    }

    private function readColumnFromRow(Variable $row, string|int $field): ?Variable
    {
        if (Variable::TYPE_ARRAY === $row->type) {
            $rowHt = $row->toArray();
            $cell = \is_int($field) ? $rowHt->findIndex($field) : $rowHt->find($field);
            if (null === $cell || $cell->isUndefined()) {
                return null;
            }

            return $cell->resolveIndirect();
        }
        if (Variable::TYPE_OBJECT === $row->type) {
            $propName = \is_string($field) ? $field : (string) $field;
            $object = $row->toObject();
            if (!$object->hasProperty($propName)) {
                return null;
            }

            return $object->getProperty($propName)->resolveIndirect();
        }

        return null;
    }

    private function storeAtKey(HashTable $out, Variable $key, Variable $value): void
    {
        $stored = new Variable();
        $stored->copyFrom($value);
        $resolved = $key->resolveIndirect();
        if (Variable::TYPE_INTEGER === $resolved->type) {
            $out->updateIndex($resolved->toInt(), $stored);

            return;
        }
        if (Variable::TYPE_STRING === $resolved->type) {
            $out->update($resolved->toString(), $stored);

            return;
        }
        if (Variable::TYPE_NULL === $resolved->type) {
            $out->update('', $stored);

            return;
        }
        throw new \LogicException(
            'array_column() index_key value must be int, string, or null in this compiler build'
        );
    }
}
