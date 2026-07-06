--TEST--
stdlib request_parse_body absent on 8.2 reference profile (ext/standard/http.c)
--FILE--
<?php
var_export(function_exists('request_parse_body'));
echo "\n";
?>
--EXPECT--
false

