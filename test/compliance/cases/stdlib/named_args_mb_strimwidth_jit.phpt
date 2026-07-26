--TEST--
mb_strimwidth named trim_marker argument (JIT, issue #23351)
--FILE--
<?php
var_export(mb_strimwidth(string: 'hello', start: 0, width: 3, trim_marker: '..'));
echo PHP_EOL;
--EXPECT--
'h..'
