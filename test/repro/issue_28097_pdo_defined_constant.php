<?php
/** Repro for #28097 — PDO::* defined()/constant() after case-sensitive ClassConst keys (#25910). */
echo 'defined_ERRMODE=', var_export(defined('PDO::ATTR_ERRMODE'), true), PHP_EOL;
echo 'constant_ERRMODE=', constant('PDO::ATTR_ERRMODE'), PHP_EOL;
echo 'defined_DEFAULT=', var_export(defined('PDO::ATTR_DEFAULT_FETCH_MODE'), true), PHP_EOL;
echo 'constant_EXCEPTION=', constant('PDO::ERRMODE_EXCEPTION'), PHP_EOL;
echo 'direct=', PDO::ATTR_ERRMODE, PHP_EOL;
$r = new ReflectionClass('PDO');
echo 'hasConstant=', var_export($r->hasConstant('ATTR_ERRMODE'), true), PHP_EOL;
echo 'wrong_case_defined=', var_export(defined('PDO::attr_errmode'), true), PHP_EOL;
