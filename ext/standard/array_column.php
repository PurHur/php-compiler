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
        $array = $frame->calledArgs[0]->resolveIndirect();
        $column = $frame->calledArgs[1]->resolveIndirect();
        $indexKeySpec = 3 === $argc ? $frame->calledArgs[2]->resolveIndirect() : null;
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_column() first argument must be an array in this compiler build');
        }
        $columnField = VmArrayColumnArg::requireStrIntArg($column, 'array_column', 1, 'column_key');
        $indexField = null;
        if (null !== $indexKeySpec && Variable::TYPE_NULL !== $indexKeySpec->type) {
            $indexField = VmArrayColumnArg::requireStrIntArg($indexKeySpec, 'array_column', 2, 'index_key');
        }

        $out = new HashTable();
        foreach ($array->toArray()->iterate(true) as $rowVar) {
            $row = $rowVar->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $row->type) {
                if (null === $indexField) {
                    $stored = new Variable();
                    $stored->null();
                    $out->append($stored);
                }
                continue;
            }
            $rowHt = $row->toArray();
            if (null !== $indexField) {
                if (!$this->rowHasField($rowHt, $indexField) || !$this->rowHasField($rowHt, $columnField)) {
                    continue;
                }
                $stored = new Variable();
                $stored->copyFrom($this->readRowField($rowHt, $columnField));
                $this->storeAtKey($out, $this->readRowField($rowHt, $indexField), $stored);
                continue;
            }
            $stored = new Variable();
            if (!$this->rowHasField($rowHt, $columnField)) {
                $stored->null();
            } else {
                $stored->copyFrom($this->readRowField($rowHt, $columnField));
            }
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
        if (!JitArrayColumnArg::guardStrIntNullOperand($context, $args[1], 'array_column', 1, 'column_key')) {
            return HashTableHelper::alloc($context);
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
            if (JITVariable::TYPE_VALUE === $args[1]->type) {
                JitArrayColumnArg::emitRuntimeColumnKeyReject($context, $args[1], 'array_column', 1, 'column_key');

                return HashTableHelper::alloc($context);
            }
            throw new \LogicException(
                'array_column() column key must be a compile-time string in this compiler build'
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
        if (null === JitStringArg::compileTimeLiteral($args[2])) {
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
                JitArrayColumnArg::emitRuntimeColumnKeyReject($context, $args[2], 'array_column', 2, 'index_key');

                return HashTableHelper::alloc($context);
            }
            throw new \LogicException(
                'array_column() index_key must be a compile-time string in this compiler build'
            );
        }
        $indexKey = $this->jitString($context, $args[2], 'array_column() index_key');

        return ArrayBuiltinHelper::buildColumnArrayWithIndex($context, $args[0], $columnKey, $indexKey);
    }

    private function rowHasField(HashTable $row, string|int $field): bool
    {
        $cell = \is_int($field) ? $row->findIndex($field) : $row->find($field);

        return null !== $cell && !$cell->isUndefined();
    }

    private function readRowField(HashTable $row, string|int $field): Variable
    {
        $cell = \is_int($field) ? $row->findIndex($field) : $row->find($field);
        if (null === $cell) {
            $missing = new Variable();
            $missing->null();

            return $missing;
        }

        return $cell->resolveIndirect();
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
