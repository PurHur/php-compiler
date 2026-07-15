--TEST--
stdlib timezone_name_from_abbr(null) — coerces to false on 8.4 profile (#19161, ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(timezone_name_from_abbr(null));
echo "\n";
?>
--EXPECT--
false
