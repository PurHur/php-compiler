--TEST--
stdlib filter_var() FILTER_VALIDATE_FLOAT ALLOW_THOUSAND JIT (#29013)
--FILE--
<?php
declare(strict_types=1);
var_export(filter_var('1,234.5', FILTER_VALIDATE_FLOAT, FILTER_FLAG_ALLOW_THOUSAND));
echo "\n";
var_export(filter_var('12,34.5', FILTER_VALIDATE_FLOAT, FILTER_FLAG_ALLOW_THOUSAND));
echo "\n";
--EXPECT--
1234.5
false
