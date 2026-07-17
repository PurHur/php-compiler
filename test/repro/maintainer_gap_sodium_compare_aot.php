<?php
declare(strict_types=1);

/**
 * AOT repro for #20081 — sodium_compare + registration of increment/add.
 * By-ref mutate APIs (increment/add) are VM-first like sodium_memzero().
 * (Avoid embedded NUL in string literals — AOT constant folding truncates them.)
 */
echo 'sodium_increment=', function_exists('sodium_increment') ? '1' : '0', "\n";
echo 'sodium_add=', function_exists('sodium_add') ? '1' : '0', "\n";
echo 'sodium_compare=', function_exists('sodium_compare') ? '1' : '0', "\n";
echo 'cmp_eq=', sodium_compare('ab', 'ab'), "\n";
echo 'cmp_lt=', sodium_compare('a', 'b'), "\n";
echo 'cmp_gt=', sodium_compare('b', 'a'), "\n";
