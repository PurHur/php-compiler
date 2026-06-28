<?php

// Issue #12904 — preg_filter() $count by-ref (ext/pcre/php_pcre.c).
$count = 0;
$out = preg_filter('/(\d+)/', '[$1]', ['a1', 'b2'], -1, $count);
echo 'count=', $count, "\n";
var_export($out);
