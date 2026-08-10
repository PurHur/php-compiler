<?php
// AOT #29385 — soft-null $flags coerce (DEP skipped on user-script AOT fold)
// asort/arsort AOT packed-list sort is a pre-existing gap (fails without null too).
error_reporting(E_ALL & ~E_DEPRECATED);
$a = [3, 1, 2];
sort($a, null);
echo implode(',', $a), "\n";
$b = [3, 1, 2];
rsort($b, null);
echo implode(',', $b), "\n";
$e = [3, 1, 2];
ksort($e, null);
echo implode(',', $e), "\n";
$f = [3, 1, 2];
krsort($f, null);
echo implode(',', $f), "\n";
