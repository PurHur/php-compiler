<?php

declare(strict_types=1);

/**
 * Repro for #24645 — array literal spread over Generator / IteratorAggregate / ArrayIterator.
 *
 * Zend: [1,2,3] / [10,20] / [1,2,3]
 * Was:  [2,3]   / [20]    / NULL (when used as call arg with nested ctor array)
 */
function g(): Generator
{
    yield 1;
    yield 2;
    yield 3;
}

echo 'gen: ';
var_export([...g()]);
echo "\n";

$ia = new class implements IteratorAggregate {
    public function getIterator(): Traversable
    {
        yield 10;
        yield 20;
    }
};
echo 'agg: ';
var_export([...$ia]);
echo "\n";

echo 'ait: ';
var_export([...new ArrayIterator([1, 2, 3])]);
echo "\n";

echo 'arr: ';
var_export([...[1, 2, 3]]);
echo "\n";
