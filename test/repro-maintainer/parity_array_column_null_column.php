<?php

declare(strict_types=1);

/**
 * Zend vs php-compiler: array_column($rows, null) returns row copies (#4306).
 *
 * php-src: ext/standard/array.c — php_array_column() column_key == NULL branch
 */

$rows = [
    ['x' => 1],
    ['x' => 2],
];
$out = array_column($rows, null);
echo json_encode($out), "\n";
echo isset($out[0]['x']) && 1 === $out[0]['x'] ? "row0\n" : "fail0\n";
echo isset($out[1]['x']) && 2 === $out[1]['x'] ? "row1\n" : "fail1\n";

$mixed = array_column([1, ['a' => 1], ['b' => 2]], null);
echo json_encode($mixed), "\n";

$keyed = array_column([
    ['id' => 1, 'n' => 'a'],
    ['id' => 2, 'n' => 'b'],
], null, 'id');
echo json_encode($keyed), "\n";
