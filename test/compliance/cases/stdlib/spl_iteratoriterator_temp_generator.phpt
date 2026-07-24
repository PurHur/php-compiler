--TEST--
SPL IteratorIterator over temporary Generator — rewind/current/valid (#22874, ext/spl/spl_iterators.c)
--FILE--
<?php
function gen()
{
    yield 1;
    yield 2;
}

$it = new IteratorIterator(gen());
$it->rewind();
var_export($it->current());
echo '/';
var_export($it->valid());
echo "\n";

$held = gen();
$it2 = new IteratorIterator($held);
$it2->rewind();
var_export($it2->current());
echo '/';
var_export($it2->valid());
echo "\n";

$ai = new IteratorIterator(new ArrayIterator([1, 2]));
$ai->rewind();
var_export($ai->current());
echo '/';
var_export($ai->valid());
echo "\n";
--EXPECT--
1/true
1/true
1/true
