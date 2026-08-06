--TEST--
PDO class constants table + defined()/constant() ClassConst lookup (#20393, #28097)
--FILE--
<?php
$r = new ReflectionClass('PDO');
$c = $r->getConstants();
echo 'count=', count($c), "\n";
echo 'has_EMULATE=', var_export(array_key_exists('ATTR_EMULATE_PREPARES', $c), true), "\n";
echo 'emulate=', var_export($c['ATTR_EMULATE_PREPARES'], true), "\n";
echo 'defined_ERRMODE=', var_export(defined('PDO::ATTR_ERRMODE'), true), "\n";
echo 'constant_ERRMODE=', constant('PDO::ATTR_ERRMODE'), "\n";
echo 'hasConstant_ERRMODE=', var_export($r->hasConstant('ATTR_ERRMODE'), true), "\n";
echo 'wrong_case_defined=', var_export(defined('PDO::attr_errmode'), true), "\n";
// Assign before var_export — direct ClassConstFetch+var_export can alias operand slots in VM.
$errMode = PDO::ATTR_ERRMODE;
$errNone = PDO::ERR_NONE;
$fetchBoth = PDO::FETCH_BOTH;
$paramStr = PDO::PARAM_STR;
$defaultFetch = PDO::ATTR_DEFAULT_FETCH_MODE;
$errException = PDO::ERRMODE_EXCEPTION;
echo 'fetch_ERRMODE=', $errMode, "\n";
echo 'err_none=', var_export($errNone, true), "\n";
echo 'defined_ERR_NONE=', var_export(defined('PDO::ERR_NONE'), true), "\n";
echo 'defined_DEFAULT=', var_export(defined('PDO::ATTR_DEFAULT_FETCH_MODE'), true), "\n";
echo 'constant_EXCEPTION=', constant('PDO::ERRMODE_EXCEPTION'), "\n";
echo 'fetch_BOTH=', $fetchBoth, "\n";
echo 'param_STR=', $paramStr, "\n";
echo 'default_fetch=', $defaultFetch, "\n";
echo 'err_exception=', $errException, "\n";
?>
--EXPECT--
count=74
has_EMULATE=true
emulate=20
defined_ERRMODE=true
constant_ERRMODE=3
hasConstant_ERRMODE=true
wrong_case_defined=false
fetch_ERRMODE=3
err_none='00000'
defined_ERR_NONE=true
defined_DEFAULT=true
constant_EXCEPTION=2
fetch_BOTH=4
param_STR=2
default_fetch=19
err_exception=2
