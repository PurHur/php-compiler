--TEST--
ucwords named string/separators arguments (JIT, issue #23226)
--FILE--
<?php
var_export(ucwords(string: 'hello world'));
echo PHP_EOL;
var_export(ucwords(string: 'a-b', separators: '-'));
echo PHP_EOL;
--EXPECT--
'Hello World'
'A-B'
