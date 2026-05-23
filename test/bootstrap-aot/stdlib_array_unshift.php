<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: array_unshift on packed list and boxed property arrays.
 */

class UnshiftBlock
{
    /** @var array<int, string> */
    public $items = [];
}

$block = new UnshiftBlock();
$block->items[] = 'mid';
echo (string) count($block->items);
array_unshift($block->items, 'head');
echo (string) count($block->items);

$list = ['b', 'c'];
$n = array_unshift($list, 'a');
echo (string) $n;
echo (string) count($list);
