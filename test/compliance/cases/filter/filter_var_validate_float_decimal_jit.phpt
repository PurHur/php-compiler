--TEST--
stdlib filter_var() FILTER_VALIDATE_FLOAT options[decimal] JIT (#29007)
--FILE--
<?php
declare(strict_types=1);
$opts = ['options' => ['decimal' => ',']];
var_export(filter_var('1,5', FILTER_VALIDATE_FLOAT, $opts));
echo "\n";
var_export(filter_var('1.5', FILTER_VALIDATE_FLOAT, $opts));
echo "\n";
--EXPECT--
1.5
false
