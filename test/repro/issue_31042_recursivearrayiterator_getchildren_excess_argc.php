<?php

declare(strict_types=1);

/**
 * RecursiveArrayIterator::getChildren excess argc (#31042).
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

$it = new RecursiveArrayIterator([1, [2, 3]]);
$it->next();
show('hasChildren', static fn () => $it->hasChildren(1));
show('getChildren', static fn () => $it->getChildren(1));
show('hasChildren_ok', static fn () => var_export($it->hasChildren(), true));
show('getChildren_ok', static fn () => get_class($it->getChildren()));
