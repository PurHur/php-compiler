--TEST--
filter_var null $options soft E_DEPRECATED then default (#31209)
--FILE--
<?php
error_reporting(E_ALL);
var_export(filter_var('1', FILTER_VALIDATE_INT, null));
echo "\n";
?>
--EXPECTF--
PHP Deprecated:  filter_var(): Passing null to parameter #3 ($options) of type array|int is deprecated in %s on line %d
1
