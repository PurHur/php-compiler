<?php

declare(strict_types=1);

/**
 * JSON encode of a loop-built int list (#36385).
 *
 * Verifies AOT does not fold `json_encode($rows)` to INIT `[]` after `$rows[]=` in a
 * loop (CFG-wide dim-mutation guard / peer #33709). Output is strlen|count|sum so the
 * column stays comparable to Zend; runtime json_decode(encode(…)) still SIGSEGV on
 * NestedJIT assoc lists/objects (peer #24137) — decode is not part of this gate.
 */

$n = 2000;
$rows = [];
for ($i = 0; $i < $n; ++$i) {
    $rows[] = $i;
}

$json = json_encode($rows);
$sum = 0;
$count = count($rows);
for ($i = 0; $i < $count; ++$i) {
    $sum += (int) $rows[$i];
}

echo strlen((string) $json), '|', $count, '|', $sum, "\n";
