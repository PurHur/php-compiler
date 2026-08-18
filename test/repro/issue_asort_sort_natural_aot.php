<?php
/**
 * Repro #32295 — asort(SORT_NATURAL) is php_natsort (php-src ext/standard/array.c).
 */
$a = ['img2', 'img10', 'img1'];
asort($a, SORT_NATURAL);
echo implode(',', $a), "\n";

$b = ['IMG12' => 'IMG12', 'img2' => 'img2', 'Img1' => 'Img1'];
asort($b, SORT_NATURAL | SORT_FLAG_CASE);
echo implode(',', $b), "\n";
