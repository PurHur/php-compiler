<?php
/** Maintainer repro for #11238 — array_multisort() combined SORT_* flag bitmasks. */
declare(strict_types=1);

$a = ['b', 'a', 'B'];
array_multisort($a, SORT_NATURAL | SORT_FLAG_CASE);
$ok = $a === ['a', 'b', 'B'];
echo $ok ? "OK\n" : 'FAIL got '.var_export($a, true)."\n";
exit($ok ? 0 : 1);
