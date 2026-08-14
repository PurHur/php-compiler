<?php

declare(strict_types=1);

/**
 * SPL aggregate iterator constructor excess argc (#31071).
 *
 * php-src: ext/spl/spl_iterators.c, ext/spl/spl_array.c
 */
function show(string $label, callable $fn): void
{
    try {
        $fn();
        echo $label, ": ACCEPTED\n";
    } catch (ArgumentCountError $e) {
        echo $label, ': ', $e->getMessage(), "\n";
    }
}

$inner = new ArrayIterator([1, 2, 3]);

show('AppendIterator', static fn () => new AppendIterator(1));
show('LimitIterator', static fn () => new LimitIterator($inner, 0, 1, 1));
show('CachingIterator', static fn () => new CachingIterator($inner, 0, 1));
show('MultipleIterator', static fn () => new MultipleIterator(0, 1));
show('NoRewindIterator', static fn () => new NoRewindIterator($inner, 1));
show('InfiniteIterator', static fn () => new InfiniteIterator($inner, 1));
show('ArrayIterator', static fn () => new ArrayIterator([1, 2, 3], 0, 1));
show('ArrayObject', static fn () => new ArrayObject([1, 2, 3], 0, null, 1));
