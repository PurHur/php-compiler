--TEST--
substr_replace named string/replace/offset/length arguments (VM, issue #23183)
--FILE--
<?php
var_export(substr_replace(string: 'abcdef', replace: 'X', offset: 2, length: 1));
echo PHP_EOL;
var_export(substr_replace(string: 'abcdef', replace: 'X', offset: 2));
echo PHP_EOL;
$rf = new ReflectionFunction('substr_replace');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), PHP_EOL;
}
--EXPECT--
'abXdef'
'abX'
string
replace
offset
length
