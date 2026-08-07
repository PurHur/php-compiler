<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ArrayColumnRuntime;
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
        // php-src ext/standard/array.stub.php — ArgumentCountError (#28691).
        $this->requireArgCountRange($frame, 'array_column', 2, 3);
        $argc = \count($frame->calledArgs);
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

        if (null === $columnField) {
            $out = null === $indexField
                ? ArrayColumnJitHelper::columnNull($ht)
                : ArrayColumnJitHelper::columnNullWithIndex($ht, $indexField);
            $frame->returnVar->array($out);

            return;
        }
        if (null !== $indexField) {
            $frame->returnVar->array(ArrayColumnJitHelper::columnWithKeyAndIndex($ht, $columnField, $indexField));

            return;
        }
        $frame->returnVar->array(ArrayColumnJitHelper::columnWithKey($ht, $columnField));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT) — peer #28228 / #28691.
        if (!$this->requireArgCountRangeJit($context, $args, 'array_column', 2, 3)) {
            return HashTableHelper::alloc($context);
        }
        $argc = \count($args);
        JitArrayElem::requireArrayParam($context, $args[0], 'array_column', 1, 'array');
        if (!JitArrayColumnArg::guardStrIntNullOperand($context, $args[1], 'array_column', 1, 'column_key')) {
            return HashTableHelper::alloc($context);
        }
        if (JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant) {
            if (2 === $argc) {
                return ArrayColumnRuntime::columnNull($context, $args[0]);
            }
            if (JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant) {
                return ArrayColumnRuntime::columnNull($context, $args[0]);
            }
            if (!JitArrayColumnArg::guardStrIntNullOperand($context, $args[2], 'array_column', 2, 'index_key')) {
                return HashTableHelper::alloc($context);
            }

            return $this->lowerNullColumnWithIndex($context, $args[0], $args[2]);
        }
        if (JITVariable::TYPE_VALUE === $args[1]->type) {
            if (2 === $argc) {
                return ArrayColumnRuntime::columnWithRuntimeKey($context, $args[0], $args[1]);
            }
            if (JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant) {
                return ArrayColumnRuntime::columnWithRuntimeKey($context, $args[0], $args[1]);
            }
            if (!JitArrayColumnArg::guardStrIntNullOperand($context, $args[2], 'array_column', 2, 'index_key')) {
                return HashTableHelper::alloc($context);
            }
            if (null !== JitStringArg::compileTimeLiteral($args[2])) {
                $indexKey = $this->jitString($context, $args[2], 'array_column() index_key');

                return ArrayColumnRuntime::columnWithRuntimeKeyAndIndex(
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
                return ArrayColumnRuntime::columnWithRuntimeKeyAndRuntimeIndex(
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
            return ArrayColumnRuntime::column($context, $args[0], $columnKey);
        }
        if (JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant) {
            return ArrayColumnRuntime::column($context, $args[0], $columnKey);
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

            return ArrayColumnRuntime::columnNullWithIndex($context, $array, $indexKeyStr);
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
            return ArrayColumnRuntime::columnNullWithRuntimeIndex($context, $array, $indexKey);
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

            return ArrayColumnRuntime::columnWithIndex($context, $array, $columnKey, $indexKeyStr);
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
            return ArrayColumnRuntime::columnWithKeyAndRuntimeIndex($context, $array, $columnKey, $indexKey);
        }
        throw new \LogicException(
            'array_column() index_key must be a string or integer in this compiler build'
        );
    }
}
