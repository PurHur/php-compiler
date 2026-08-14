<?php

declare(strict_types=1);

/**
 * ArrayIterator / RecursiveArrayIterator residual excess argc (#30963).
 *
 * php-src: ext/spl/spl_array.c
 */
function show(string $label, callable $fn): void
{
    try {
        $fn();
        echo $label, ": OK\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}

$a = new ArrayIterator(['b' => 2, 'a' => 1]);
show('seek', static fn () => $a->seek(0, 1));
show('getArrayCopy', static fn () => $a->getArrayCopy(1));
show('uasort', static fn () => $a->uasort(static fn ($x, $y) => $x <=> $y, 1));
show('uksort', static fn () => $a->uksort(static fn ($x, $y) => $x <=> $y, 1));
show('getFlags', static fn () => $a->getFlags(1));
show('setFlags', static fn () => $a->setFlags(0, 1));
show('offsetExists', static fn () => $a->offsetExists('a', 1));
show('offsetGet', static fn () => $a->offsetGet('a', 1));
show('offsetUnset', static fn () => $a->offsetUnset('a', 1));
show('offsetSet', static fn () => $a->offsetSet('c', 3, 1));
show('append', static fn () => $a->append(9, 1));
$r = new RecursiveArrayIterator([1, [2]]);
show('hasChildren', static fn () => $r->hasChildren(1));
$a->seek(0);
show('seek_ok', static fn () => $a->current());
