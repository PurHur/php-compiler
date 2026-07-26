--TEST--
iconv/iconv_strlen/iconv_substr named arguments (VM, issue #23307)
--FILE--
<?php
var_export(iconv(from_encoding: 'UTF-8', to_encoding: 'UTF-8', string: 'a'));
echo PHP_EOL;
var_export(iconv_strlen(string: 'ä', encoding: 'UTF-8'));
echo PHP_EOL;
var_export(iconv_substr(string: 'abcdef', offset: 1, length: 2, encoding: 'UTF-8'));
echo PHP_EOL;
$rf = new ReflectionFunction('iconv');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), PHP_EOL;
}
$rf = new ReflectionFunction('iconv_strlen');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), PHP_EOL;
}
$rf = new ReflectionFunction('iconv_substr');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), PHP_EOL;
}
--EXPECT--
'a'
1
'bc'
from_encoding
to_encoding
string
string
encoding
string
offset
length
encoding
