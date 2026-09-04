<?php

declare(strict_types=1);

/**
 * #36382 — non-escaping packed string arrays inside user functions must not
 * loadValue+delref the `[N x %__string__*]` aggregate at return (module verify).
 * php-src: Zend/zend_hash.c zend_array_destroy (per-bucket release).
 */
function f_36382_native_str_arr(): void
{
    $a = ['h'];
    $b = $a;
    echo $b[0], "\n";
}

f_36382_native_str_arr();
