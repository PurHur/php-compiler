--TEST--
stdlib implode()/join() named separator:/array: arguments (#9985, ext/standard/string.c)
--FILE--
<?php
var_export(implode(separator: '-', array: ['a', 'b']));
echo "\n";
var_export(join(separator: '|', array: ['x', 'y']));
echo "\n";
var_export(implode(glue: ':', pieces: ['1', '2']));
echo "\n";
var_export(implode('-', ['a', 'b']));
echo "\n";
var_export(implode(['a', 'b']));
echo "\n";
--EXPECT--
'a-b'
'x|y'
'1:2'
'a-b'
'ab'
