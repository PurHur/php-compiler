--TEST--
iconv_strpos/iconv_strrpos named arguments use encoding (JIT, issue #24364)
--FILE--
<?php
var_export(iconv_strpos(haystack: 'abc', needle: 'b', offset: 0, encoding: 'UTF-8'));
echo PHP_EOL;
var_export(iconv_strrpos(haystack: 'abcb', needle: 'b', encoding: 'UTF-8'));
echo PHP_EOL;
--EXPECT--
1
3
