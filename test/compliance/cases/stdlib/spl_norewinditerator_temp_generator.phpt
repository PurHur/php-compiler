--TEST--
SPL NoRewindIterator over temporary Generator — current/key after rewind (#22875, ext/spl/spl_iterators.c)
--FILE--
<?php
function gen()
{
    yield 'a' => 1;
    yield 'b' => 2;
}

$nr = new NoRewindIterator(gen());
$nr->rewind();
var_export($nr->current());
echo '/';
var_export($nr->key());
echo "\n";

$nr2 = new NoRewindIterator(new ArrayIterator(['a' => 1, 'b' => 2]));
$nr2->rewind();
var_export($nr2->current());
echo '/';
var_export($nr2->key());
echo "\n";
--EXPECT--
1/'a'
1/'a'
