--TEST--
CLI $_SERVER SCRIPT_NAME populated for invoked script (#17574, sapi/cli)
--FILE--
<?php
declare(strict_types=1);

var_export(isset($_SERVER['SCRIPT_NAME']));
echo "\n";
echo $_SERVER['SCRIPT_NAME'] ?? 'unset';
echo "\n";
var_export(isset($_SERVER['PHP_SELF']));
echo "\n";
--EXPECTF--
true
%s
true
