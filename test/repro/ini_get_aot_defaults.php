<?php
// #33059 — thin AOT must match Zend/VM defaults (not string "0").
foreach (['precision', 'serialize_precision', 'memory_limit', 'bogus_key'] as $k) {
    echo $k, '=', var_export(ini_get($k), true), "\n";
}
