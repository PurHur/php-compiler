--TEST--
stdlib wordwrap() width:/break:/cut: named parameters (#9524, #28938, ext/standard/string.c)
--FILE--
<?php
var_export(wordwrap('hello world', width: 5));
echo "\n";
var_export(wordwrap(string: 'hello world', width: 5, break: "\n"));
echo "\n";
// Named break without width — sparse calledArgs must use defaults (#28938).
var_export(wordwrap('aa bb', break: '-'));
echo "\n";
var_export(wordwrap(string: 'aa bb', break: '-'));
echo "\n";
var_export(wordwrap('aa bb', cut_long_words: false));
echo "\n";
var_export(wordwrap('aa bb', 2, '-', cut_long_words: true));
echo "\n";
--EXPECT--
'hello
world'
'hello
world'
'aa bb'
'aa bb'
'aa bb'
'aa-bb'
