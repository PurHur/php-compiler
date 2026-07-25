--TEST--
strpos/stripos/strrpos/strstr named haystack/needle arguments (VM, issue #23182)
--FILE--
<?php
var_export(strpos(haystack: 'abcdef', needle: 'cd'));
echo PHP_EOL;
var_export(stripos(haystack: 'ABCDEF', needle: 'cd'));
echo PHP_EOL;
var_export(strrpos(haystack: 'ab cd cd', needle: 'cd'));
echo PHP_EOL;
var_export(strstr(haystack: 'abcdef', needle: 'cd'));
echo PHP_EOL;
var_export(strstr(haystack: 'abcdef', needle: 'cd', before_needle: true));
echo PHP_EOL;
$rf = new ReflectionFunction('strstr');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), PHP_EOL;
}
--EXPECT--
2
2
6
'cdef'
'ab'
haystack
needle
before_needle
