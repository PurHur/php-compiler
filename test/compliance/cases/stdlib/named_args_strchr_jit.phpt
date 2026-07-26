--TEST--
strchr named haystack/needle/before_needle arguments (JIT, issue #23218)
--FILE--
<?php
var_export(strchr(haystack: 'abcdef', needle: 'd'));
echo PHP_EOL;
var_export(strchr(haystack: 'abcdef', needle: 'd', before_needle: true));
echo PHP_EOL;
--EXPECT--
'def'
'abc'
