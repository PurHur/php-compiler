--TEST--
AOT: array_unique() SORT_STRING|SORT_FLAG_CASE — case-fold dedup (#29114, re-#4253)
--FILE--
<?php
// Avoid var_export(array) — thin standalone AOT aborts without Runtime->vm (#26855).
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
