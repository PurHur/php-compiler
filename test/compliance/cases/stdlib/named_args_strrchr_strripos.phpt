--TEST--
strrchr/strripos/stristr named haystack/needle arguments (VM, issue #24038)
--FILE--
<?php
var_export(strrchr(haystack: 'abcbd', needle: 'b'));
echo PHP_EOL;
var_export(strripos(haystack: 'ababd', needle: 'AB'));
echo PHP_EOL;
var_export(stristr(haystack: 'abCde', needle: 'c'));
echo PHP_EOL;
var_export(stristr(haystack: 'abCde', needle: 'c', before_needle: true));
echo PHP_EOL;
foreach (['strrchr', 'strripos', 'stristr'] as $fn) {
    $rf = new ReflectionFunction($fn);
    echo $fn, ':';
    foreach ($rf->getParameters() as $p) {
        echo $p->getName(), ',';
    }
    echo PHP_EOL;
}
--EXPECT--
'bd'
2
'Cde'
'ab'
strrchr:haystack,needle,
strripos:haystack,needle,offset,
stristr:haystack,needle,before_needle,
