--TEST--
stdlib array_unique() SORT_STRING|SORT_FLAG_CASE (#29114, re-#4253, ext/standard/array.c)
--FILE--
<?php
$u1 = array_unique(['A', 'a', 'B', 'b'], SORT_STRING | SORT_FLAG_CASE);
echo implode(',', $u1), '|', count($u1), PHP_EOL;
$u2 = array_unique(['a', 'A', 'b', 'B'], SORT_STRING | SORT_FLAG_CASE);
echo implode(',', $u2), '|', count($u2), PHP_EOL;
$u3 = array_unique(['Foo', 'foo', 'BAR', 'bar', 'Baz'], SORT_STRING | SORT_FLAG_CASE);
echo implode(',', $u3), '|', count($u3), PHP_EOL;
--EXPECT--
A,B|2
a,b|2
Foo,BAR,Baz|3
