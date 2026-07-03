<?php

declare(strict_types=1);

// FCC callback + inline nested haystack (#15490).
$r = array_filter(str_split(str_repeat('a1', 1)), is_numeric(...));
var_export($r);
echo "\n";

$h = str_split('a1');
$r2 = array_filter($h, is_numeric(...));
var_export($r2);
echo "\n";

if ($r !== [1 => '1'] || $r2 !== [1 => '1']) {
    echo "fail: unexpected result\n";
    exit(1);
}
echo "ok\n";
