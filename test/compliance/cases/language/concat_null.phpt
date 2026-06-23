--TEST--
String concatenation: null coerces to empty string (VM)
--FILE--
<?php
var_export('a' . null);
echo "\n";
var_export(null . 'b');
echo "\n";
var_export(null . null);
echo "\n";
--EXPECT--
'a'
'b'
''
