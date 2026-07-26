--TEST--
mb_strstr/mb_stristr/mb_strrchr/mb_strrichr named before_needle (VM, #23350, ext/mbstring/mbstring.stub.php)
--FILE--
<?php
foreach (['mb_strstr', 'mb_stristr', 'mb_strrchr', 'mb_strrichr'] as $fn) {
    $rf = new ReflectionFunction($fn);
    echo $fn, ':', implode(',', array_map(static fn ($p) => $p->getName(), $rf->getParameters())), "\n";
}
var_export(mb_strstr(haystack: 'abc', needle: 'b', before_needle: true));
echo PHP_EOL;
var_export(mb_strstr(haystack: 'abc', needle: 'b', before_needle: false));
echo PHP_EOL;
var_export(mb_stristr(haystack: 'aBc', needle: 'b', before_needle: true));
echo PHP_EOL;
var_export(mb_strrchr(haystack: 'abcb', needle: 'b', before_needle: true));
echo PHP_EOL;
var_export(mb_strrichr(haystack: 'abcB', needle: 'b', before_needle: true));
echo PHP_EOL;
--EXPECT--
mb_strstr:haystack,needle,before_needle,encoding
mb_stristr:haystack,needle,before_needle,encoding
mb_strrchr:haystack,needle,before_needle,encoding
mb_strrichr:haystack,needle,before_needle,encoding
'a'
'bc'
'a'
'abc'
'abc'
