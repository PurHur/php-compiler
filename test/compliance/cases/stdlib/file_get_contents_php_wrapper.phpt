--TEST--
stdlib file_get_contents() php://stdin and php://output return empty string (issue #18403)
--FILE--
<?php
var_export(file_get_contents('php://stdin'));
echo "\n";
var_export(file_get_contents('php://output'));
echo "\n";
?>
--EXPECT--
''
''
