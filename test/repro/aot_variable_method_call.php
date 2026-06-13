<?php

declare(strict_types=1);

/**
 * Issue #8407: `$obj->method()` after assigned `new` must compile and run in AOT.
 */

class Counter
{
    public function inc(int $n): int
    {
        return $n + 1;
    }
}

$counter = new Counter();
echo $counter->inc(1);
