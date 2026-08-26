<?php
/**
 * #34947 — json_encode(JSON_PARTIAL_OUTPUT_ON_ERROR) must keep JSON on depth overflow.
 *
 * php-src ext/json/json_encoder.c: after encoding children, depth > max_depth sets
 * PHP_JSON_ERROR_DEPTH; with PARTIAL the buffer is kept (SUCCESS). Without PARTIAL → false.
 */
$nested = ['a' => ['b' => ['c' => 1]]];

echo "== plain_depth2 ==\n";
var_export(json_encode($nested, 0, 2));
echo "\n";
echo 'err=', json_last_error(), ' ', json_last_error_msg(), "\n";

echo "== partial_depth2 ==\n";
var_export(json_encode($nested, JSON_PARTIAL_OUTPUT_ON_ERROR, 2));
echo "\n";
echo 'err=', json_last_error(), ' ', json_last_error_msg(), "\n";

echo "== partial_depth1 ==\n";
var_export(json_encode($nested, JSON_PARTIAL_OUTPUT_ON_ERROR, 1));
echo "\n";
echo 'err=', json_last_error(), ' ', json_last_error_msg(), "\n";

echo "== partial_within ==\n";
var_export(json_encode($nested, JSON_PARTIAL_OUTPUT_ON_ERROR, 3));
echo "\n";
echo 'err=', json_last_error(), ' ', json_last_error_msg(), "\n";

$runtime = $nested;
echo "== runtime_partial_depth2 ==\n";
var_export(json_encode($runtime, JSON_PARTIAL_OUTPUT_ON_ERROR, 2));
echo "\n";
echo 'err=', json_last_error(), ' ', json_last_error_msg(), "\n";
