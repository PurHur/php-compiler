--TEST--
stdlib filter_input() CGI query snapshot ignores later $_GET mutation (#19640)
--GET--
x=42
--FILE--
<?php
var_export(filter_input(INPUT_GET, 'x', FILTER_VALIDATE_INT));
echo "\n";
$_GET['x'] = '99';
var_export(filter_input(INPUT_GET, 'x', FILTER_VALIDATE_INT));
echo "\n";
var_export($_GET['x']);
echo "\n";
--EXPECT--
42
42
'99'
