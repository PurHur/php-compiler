--TEST--
iconv_strpos/iconv_strrpos named arguments use encoding (VM, issue #24364)
--FILE--
<?php
var_export(iconv_strpos(haystack: 'abc', needle: 'b', offset: 0, encoding: 'UTF-8'));
echo PHP_EOL;
var_export(iconv_strrpos(haystack: 'abcb', needle: 'b', encoding: 'UTF-8'));
echo PHP_EOL;
$rf = new ReflectionFunction('iconv_strpos');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), PHP_EOL;
}
$rf = new ReflectionFunction('iconv_strrpos');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), PHP_EOL;
}
try {
    iconv_strpos(haystack: 'abc', needle: 'b', offset: 0, charset: 'UTF-8');
    echo "charset_accepted\n";
} catch (Error $e) {
    echo 'charset_rejected', PHP_EOL;
}
--EXPECT--
1
3
haystack
needle
offset
encoding
haystack
needle
encoding
charset_rejected
