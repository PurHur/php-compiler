<?php

/**
 * #35027 — AOT json_encode/serialize(float) must use PG(serialize_precision),
 * not `(string)` cast / PG(precision). Leftover of #35020 / #32328.
 *
 * php-src: ext/json/json_encoder.c php_json_encode_double;
 *          ext/standard/var.c php_var_serialize_intern double branch.
 */
ini_set('serialize_precision', '10');
echo json_encode(1 / 3), "\n";
echo serialize(1 / 3), "\n";
ini_set('serialize_precision', '17');
echo json_encode(0.1), "\n";
echo serialize(0.1), "\n";
ini_set('serialize_precision', '15');
echo ini_get('serialize_precision'), "\n";
echo json_encode(1 / 3), "\n";
ini_set('serialize_precision', '-1');
echo json_encode(0.1), "\n";
echo serialize(0.1), "\n";
