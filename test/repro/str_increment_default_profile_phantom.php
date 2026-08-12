<?php
/** Repro for #24820 / #16292 / #30511 — str_increment/str_decrement phantom on default (Zend 8.2) profile. */
echo 'str_increment=', function_exists('str_increment') ? 'Y' : 'N', "\n";
echo 'str_decrement=', function_exists('str_decrement') ? 'Y' : 'N', "\n";
try {
    echo 'call_inc=', str_increment('a'), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    echo 'call_dec=', str_decrement('b'), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
