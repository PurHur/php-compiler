<?php

declare(strict_types=1);

/**
 * #36382 — string-key HT write of a packed native string[] (KIND_VALUE aggregate)
 * must box via hashtable, not store `[N x %__string__*]` into `__value__*`.
 * php-src: Zend/zend_hash.c zend_hash_update / IS_ARRAY.
 */
function f_36382_ht_string_key_native_arr(): void
{
    $h = [];
    $h['a'] = ['x'];
    echo $h['a'][0], "\n";
}

f_36382_ht_string_key_native_arr();
