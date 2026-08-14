<?php

declare(strict_types=1);

/**
 * ArrayIterator / SplStack iterator method excess argc (#30911).
 *
 * php-src: ext/spl/spl_array.c / spl_dllist.c — ZEND_PARSE_PARAMETERS_NONE
 */
function show(string $label, callable $fn): void
{
    try {
        $v = $fn();
        echo $label, ': OK ', var_export($v, true), "\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}

$a = new ArrayIterator([1, 2, 3]);
show('current', fn () => $a->current(1));
show('key', fn () => $a->key(1));
show('next', fn () => $a->next(1));
show('rewind', fn () => $a->rewind(1));
show('valid', fn () => $a->valid(1));
show('count', fn () => $a->count(1));
show('serialize', fn () => $a->serialize(1));
$s = new SplStack();
$s->push(1);
show('top', fn () => $s->top(1));
show('pop', fn () => $s->pop(1));
show('stack_count', fn () => $s->count(1));
$a->rewind();
show('ok_current', fn () => $a->current());
show('ok_top', fn () => $s->top());
