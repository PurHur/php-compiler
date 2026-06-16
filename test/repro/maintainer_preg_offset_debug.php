<?php

declare(strict_types=1);

preg_match('/(a)/', 'abc', $m, PREG_OFFSET_CAPTURE);
echo 'm0: ', $m[0][0], ':', $m[0][1], "\n";

$r2 = preg_match('/(a)/', 'xxabc', $m2, PREG_OFFSET_CAPTURE, 2);
echo 'r2=', var_export($r2, true), ' last=', preg_last_error(), ' m2=', var_export($m2, true), "\n";

preg_match_all('/(\d+)/', 'a1b22', $all, PREG_PATTERN_ORDER | PREG_OFFSET_CAPTURE);
echo 'all: ', $all[1][0][0], ':', $all[1][0][1], "\n";

preg_match_all('/(\d+)/', 'a1b22', $m3, PREG_OFFSET_CAPTURE, 1);
echo 'm3 count: ', count($m3), "\n";
var_export($m3);
echo "\n";
