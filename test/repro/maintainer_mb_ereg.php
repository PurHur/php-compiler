<?php

declare(strict_types=1);

/**
 * Issue #4635 — mb_ereg*() multibyte POSIX regex API (ext/mbstring/php_mbregex.c).
 */
var_dump(function_exists('mb_ereg'));
var_dump(function_exists('mb_regex_encoding'));
if (mb_ereg('^[a-z]+$', 'hello')) {
    echo "match\n";
}
