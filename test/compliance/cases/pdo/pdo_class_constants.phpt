--TEST--
PDO class constants table + defined()/constant() ClassConst lookup (#20393)
--FILE--
<?php
$r = new ReflectionClass('PDO');
$c = $r->getConstants();
echo 'count=', count($c), "\n";
echo 'has_EMULATE=', var_export(array_key_exists('ATTR_EMULATE_PREPARES', $c), true), "\n";
echo 'emulate=', var_export($c['ATTR_EMULATE_PREPARES'], true), "\n";
echo 'defined_ERRMODE=', var_export(defined('PDO::ATTR_ERRMODE'), true), "\n";
echo 'constant_ERRMODE=', constant('PDO::ATTR_ERRMODE'), "\n";
// Assign before var_export — direct ClassConstFetch+var_export can alias operand slots in VM.
$errMode = PDO::ATTR_ERRMODE;
$errNone = PDO::ERR_NONE;
$fetchBoth = PDO::FETCH_BOTH;
$paramStr = PDO::PARAM_STR;
echo 'fetch_ERRMODE=', $errMode, "\n";
echo 'err_none=', var_export($errNone, true), "\n";
echo 'defined_ERR_NONE=', var_export(defined('PDO::ERR_NONE'), true), "\n";
echo 'fetch_BOTH=', $fetchBoth, "\n";
echo 'param_STR=', $paramStr, "\n";
?>
--EXPECT--
count=74
has_EMULATE=true
emulate=20
defined_ERRMODE=true
constant_ERRMODE=3
fetch_ERRMODE=3
err_none='00000'
defined_ERR_NONE=true
fetch_BOTH=4
param_STR=2
