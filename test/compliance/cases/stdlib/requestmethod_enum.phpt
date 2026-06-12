--TEST--
stdlib RequestMethod enum — string-backed cases (#7230)
--FILE--
<?php
var_export(enum_exists('RequestMethod', false));
echo "\n";
var_export(unitenum_exists('RequestMethod'));
echo "\n";
var_export(RequestMethod::Post->name);
echo "\n";
var_export(RequestMethod::Post->value);
echo "\n";
var_export(RequestMethod::Get->value);
echo "\n";
var_export(RequestMethod::Put->value);
echo "\n";
--EXPECT--
true
false
'Post'
'POST'
'GET'
'PUT'
