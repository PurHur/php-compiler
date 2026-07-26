--TEST--
strtr named string/from/to arguments (JIT, issue #23215)
--FILE--
<?php
var_export(strtr(string: 'abc', from: 'a', to: 'x'));
echo PHP_EOL;
var_export(strtr(string: 'baab', from: ['a' => 'o']));
echo PHP_EOL;
--EXPECT--
'xbc'
'boob'
