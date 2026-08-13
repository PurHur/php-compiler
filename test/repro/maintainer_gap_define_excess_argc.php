<?php

/**
 * #30573 — define() excess argc → ArgumentCountError (Zend/zend_builtin_functions.c).
 * 3-arg form remains legal; 4th arg must not define the constant.
 */
try {
    var_export(define('ZZZ_DEF4', 1, false, 'extra'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo defined('ZZZ_DEF4') ? "defined\n" : "undef\n";

try {
    var_export(define('ZZZ_DEF3', 2, false));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo defined('ZZZ_DEF3') ? "defined3\n" : "undef3\n";
