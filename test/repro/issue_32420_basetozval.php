<?php
// Repro #32420 — leftover Module.php always-on phpc_basetozval_result dropped.
// base_convert AOT must still compile and match Zend (php-src ext/standard/math.c).
var_dump(base_convert(255, 10, 2));
echo "ok\n";
