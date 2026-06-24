--TEST--
stdlib wordwrap() width:/break:/cut: named parameters (#9524, ext/standard/string.c)
--FILE--
<?php
var_export(wordwrap('hello world', width: 5));
echo "\n";
var_export(wordwrap(string: 'hello world', width: 5, break: "\n"));
echo "\n";
--EXPECT--
'hello
world'
'hello
world'
