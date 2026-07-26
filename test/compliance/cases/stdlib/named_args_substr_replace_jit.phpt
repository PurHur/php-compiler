--TEST--
substr_replace named string/replace/offset/length arguments (JIT, issue #23183)
--FILE--
<?php
var_export(substr_replace(string: 'abcdef', replace: 'X', offset: 2, length: 1));
echo PHP_EOL;
var_export(substr_replace(string: 'abcdef', replace: 'X', offset: 2));
echo PHP_EOL;
--EXPECT--
'abXdef'
'abX'
