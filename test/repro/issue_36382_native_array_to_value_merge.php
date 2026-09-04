<?php

declare(strict_types=1);

/**
 * #36382 — packed native string[] assigned across a branch merge into a VALUE box
 * must materialize to a hashtable (not store `[N x %__string__*]` into `__value__*`).
 * php-src: Zend/zend_execute.c zend_assign_to_variable / IS_ARRAY zval.
 */
function f_36382_native_arr_merge(bool $take): void
{
    $a = ['h'];
    if ($take) {
        $b = $a;
    } else {
        $b = null;
    }
    if (is_array($b)) {
        echo $b[0], "\n";
    } else {
        echo "n\n";
    }
}

f_36382_native_arr_merge(true);
f_36382_native_arr_merge(false);
