--TEST--
language Range::from() inclusive int/string intervals on 8.4 profile (#17427, ext/standard/range.c)
--FILE--
<?php
$intParts = [];
foreach (Range::from(1, 3) as $i) {
    $intParts[] = $i;
}
$stringParts = [];
foreach (Range::from('a', 'c') as $c) {
    $stringParts[] = $c;
}
echo $intParts === [1, 2, 3] ? "int=ok\n" : "int=fail\n";
echo $stringParts === ['a', 'b', 'c'] ? "char=ok\n" : "char=fail\n";
echo class_exists('Range', false) ? "exists=ok\n" : "exists=fail\n";
--EXPECT--
int=ok
char=ok
exists=ok
