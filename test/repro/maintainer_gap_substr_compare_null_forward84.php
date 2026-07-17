<?php
// #20164 — substr_compare(null) TypeError on PHP_COMPILER_PROFILE=8.4
try {
    echo 'haystack:'.substr_compare(null, 'a', 0)."\n";
} catch (Throwable $e) {
    echo get_class($e).':'.$e->getMessage()."\n";
}
try {
    echo 'needle:'.substr_compare('a', null, 0)."\n";
} catch (Throwable $e) {
    echo get_class($e).':'.$e->getMessage()."\n";
}
