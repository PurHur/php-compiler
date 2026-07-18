<?php
/** Repro for #20393 — PDO class constants + defined()/constant() */
$r = new ReflectionClass('PDO');
$c = $r->getConstants();
echo 'count=', count($c), PHP_EOL;
echo 'has_EMULATE=', var_export(array_key_exists('ATTR_EMULATE_PREPARES', $c), true), PHP_EOL;
echo 'defined_ERRMODE=', var_export(defined('PDO::ATTR_ERRMODE'), true), PHP_EOL;
try {
    echo 'constant_ERRMODE=', constant('PDO::ATTR_ERRMODE'), PHP_EOL;
} catch (Throwable $e) {
    echo 'constant_ERR=', $e->getMessage(), PHP_EOL;
}
echo 'fetch_ERRMODE=', PDO::ATTR_ERRMODE, PHP_EOL;
