--TEST--
mb_chr/mb_ord named arguments (VM, issue #23291)
--FILE--
<?php
var_export(mb_chr(codepoint: 0x41));
echo PHP_EOL;
var_export(mb_ord(string: 'A'));
echo PHP_EOL;
$rf = new ReflectionFunction('mb_chr');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), PHP_EOL;
}
$rf = new ReflectionFunction('mb_ord');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), PHP_EOL;
}
--EXPECT--
'A'
65
codepoint
encoding
string
encoding
