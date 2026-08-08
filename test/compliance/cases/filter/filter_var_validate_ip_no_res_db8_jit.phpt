--TEST--
stdlib filter_var() FILTER_VALIDATE_IP NO_RES_RANGE 2001:db8 JIT (#29009)
--FILE--
<?php
declare(strict_types=1);
var_export(filter_var('2001:db8::1', FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE));
echo "\n";
var_export(filter_var('2001:4860:4860::8888', FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE));
echo "\n";
--EXPECT--
false
'2001:4860:4860::8888'
