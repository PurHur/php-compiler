--TEST--
chr named codepoint argument (JIT, issue #23240)
--FILE--
<?php
var_export(chr(codepoint: 65));
echo PHP_EOL;
--EXPECT--
'A'
