<?php
/**
 * #32326 — json_encode(INF/NAN) must be false + JSON_ERROR_INF_OR_NAN.
 * php-src ext/json/json_encoder.c php_json_encode_double.
 */
$r = json_encode(INF);
echo $r === false ? "false\n" : (string) $r."\n";
echo json_last_error() === 7 ? "7\n" : json_last_error()."\n";
$n = acos(2);
$r = json_encode($n);
echo $r === false ? "false\n" : (string) $r."\n";
echo json_last_error() === 7 ? "7\n" : json_last_error()."\n";
echo json_encode(1.5), "\n";
echo json_encode(NAN, JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
echo json_last_error() === 7 ? "7\n" : json_last_error()."\n";
