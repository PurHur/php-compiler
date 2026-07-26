--TEST--
str_repeat named string/times arguments (JIT, issue #23204)
--FILE--
<?php
var_export(str_repeat(string: 'x', times: 3));
echo PHP_EOL;
--EXPECT--
'xxx'
