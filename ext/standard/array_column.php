<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_column() for a list of associative arrays and a string column key (subset of PHP).
 */
final class array_column extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('array_column() requires two or three arguments in this compiler build');
        }
        if (3 === $argc) {
            throw new \LogicException('array_column() index_key is not supported in this compiler build');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        $column = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_column() first argument must be an array in this compiler build');
        }
        if (Variable::TYPE_STRING !== $column->type) {
            throw new \LogicException('array_column() column key must be a string in this compiler build');
        }
        $key = $column->toString();
        $out = new HashTable();
        foreach ($array->toArray()->iterate(true) as $rowVar) {
            $row = $rowVar->resolveIndirect();
            $stored = new Variable();
            if (Variable::TYPE_ARRAY !== $row->type) {
                $stored->null();
            } else {
                $cell = $row->toArray()->find($key);
                if (null === $cell || $cell->isUndefined()) {
                    $stored->null();
                } else {
                    $stored->copyFrom($cell);
                }
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
        if (3 === $argc) {
            throw new \LogicException('array_column() index_key is not supported in this compiler build');
        }
        throw new \LogicException('array_column() is not implemented for JIT in this compiler build');
    }
}
