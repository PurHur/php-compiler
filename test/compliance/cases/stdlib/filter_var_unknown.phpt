--TEST--
stdlib filter_var() unknown filter id — E_WARNING + false (php-src-strict)
--FILE--
<?php
var_export(@filter_var('x', 99999));
echo "\n";
--EXPECT--
false
