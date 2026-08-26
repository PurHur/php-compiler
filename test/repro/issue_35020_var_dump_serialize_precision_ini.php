<?php
/**
 * #35020 — var_dump(float) must honor ini_set('serialize_precision')
 * (leftover of #32328 default -1 path).
 *
 * php-src: ext/standard/var.c php_var_dump IS_DOUBLE
 *          zend_strpprintf("%.*H", (int)PG(serialize_precision))
 */
ini_set('serialize_precision', '17');
var_dump(0.1);
var_dump(1 / 3);
var_dump(0.1 + 0.2);
ini_set('serialize_precision', '14');
var_dump(0.1);
var_dump(1 / 3);
ini_set('serialize_precision', '-1');
var_dump(0.1);
var_dump(1 / 3);
