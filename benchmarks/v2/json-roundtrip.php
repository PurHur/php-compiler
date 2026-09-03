<?php

declare(strict_types=1);

/**
 * JSON encode/decode round-trip (#36385).
 * Flat int list — nested object graphs still diverge under AOT json_*.
 */

$n = 2000;
$rows = [];
for ($i = 0; $i < $n; ++$i) {
    $rows[] = $i;
}

$json = json_encode($rows);
$decoded = json_decode((string) $json, true);
$sum = 0;
$count = 0;
if (is_array($decoded)) {
    $count = count($decoded);
    foreach ($decoded as $v) {
        $sum += (int) $v;
    }
}

echo strlen((string) $json), '|', $count, '|', $sum, "\n";
