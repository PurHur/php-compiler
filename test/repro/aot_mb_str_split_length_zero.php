<?php

// AOT: mb_str_split() length <= 0 must throw ValueError, not segfault (re maintainer_gap_mb_str_split).
// php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_str_split)

var_export(mb_str_split('', 1));
echo "\n";

try {
    mb_str_split('x', 0);
    echo "fail: no exception\n";
} catch (ValueError $e) {
    echo "length_zero=ValueError\n";
}
