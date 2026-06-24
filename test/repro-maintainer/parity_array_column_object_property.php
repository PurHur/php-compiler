<?php
/** Maintainer repro for #11236 — array_column() object rows with public properties. */
declare(strict_types=1);

$r = array_column([(object) ['id' => 10], (object) ['id' => 20]], 'id');
$ok = $r === [10, 20];
echo $ok ? "OK\n" : 'FAIL got '.var_export($r, true)."\n";
exit($ok ? 0 : 1);
