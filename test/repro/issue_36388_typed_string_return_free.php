<?php

/**
 * Typed :string return must free under AOT after unset (#36388).
 *
 * php-src: Zend/zend_execute.c ZEND_RETURN — ZVAL_COPY then CV destroy;
 * ZEND_ASSIGN of owning `__string__*` call temps must move (not addref+keep).
 */
function ret(int $n): string
{
    $s = '';
    for ($i = 0; $i < $n; $i++) {
        $s .= 'x';
    }

    return $s;
}

function consume(int $n): void
{
    $u0 = memory_get_usage(false);
    $x = ret($n);
    $u1 = memory_get_usage(false);
    unset($x);
    $u2 = memory_get_usage(false);
    echo 'in_fn d1=', ($u1 - $u0), ' left=', ($u2 - $u0), ' freed=', ($u2 < $u1 ? 'y' : 'n'), "\n";
    echo ($u2 < $u1 ? "unset_ok\n" : "unset_bad\n");
    echo ($u2 <= $u0 + 256 ? "floor_ok\n" : "floor_bad\n");
}

consume(4000);
