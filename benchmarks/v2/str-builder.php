<?php

declare(strict_types=1);

/**
 * String builder — `.=` and implode (#36385).
 */

$rows = 4000;
$buf = '';
for ($i = 0; $i < $rows; ++$i) {
    $buf .= 'row-'.$i.';';
}

$parts = [];
for ($i = 0; $i < $rows; ++$i) {
    $parts[] = (string) ($i % 100000);
}
$joined = implode(',', $parts);

echo strlen($buf), '|', strlen($joined), '|', substr($joined, 0, 5), "\n";
