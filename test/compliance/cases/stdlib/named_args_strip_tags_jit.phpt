--TEST--
strip_tags named string/allowed_tags arguments (JIT, issue #23217)
--FILE--
<?php
var_export(strip_tags(string: '<b>x</b>', allowed_tags: '<b>'));
echo PHP_EOL;
var_export(strip_tags(string: '<b>x</b>'));
echo PHP_EOL;
--EXPECT--
'<b>x</b>'
'x'
