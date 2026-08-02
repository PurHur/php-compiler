--TEST--
AOT: Generator::send after current injects yield value (#26819)
--FILE--
<?php
function g()
{
    $a = yield 1;
    yield $a;
}
$g = g();
echo $g->current(), '|';
echo $g->send('x'), '|';
echo "\n";
--EXPECT--
1|x|
--EXPECT_EXIT--
0
