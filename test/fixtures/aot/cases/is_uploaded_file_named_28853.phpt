--TEST--
AOT: is_uploaded_file named Zend stub param filename (#28853)
--FILE--
<?php
var_export(is_uploaded_file(filename: '/nope'));
echo PHP_EOL;
--EXPECT--
false
