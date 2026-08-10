--TEST--
stdlib inet_pton/inet_ntop(null) JIT — DEP+coerce on 8.4 forward profile (#20303)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
var_export(inet_pton(null));
echo "\n";
var_export(inet_ntop(null));
echo "\n";
?>
--EXPECTF--
PHP Deprecated:  inet_pton(): Passing null to parameter #1 ($ip) of type string is deprecated in %s on line %d
PHP Deprecated:  inet_ntop(): Passing null to parameter #1 ($ip) of type string is deprecated in %s on line %d
false
false
