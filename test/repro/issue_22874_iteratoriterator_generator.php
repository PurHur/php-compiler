<?php
function gen() { yield 1; yield 2; }
$it = new IteratorIterator(gen());
$it->rewind();
var_export($it->current());
echo '/';
var_export($it->valid());
echo "\n";
$ai = new IteratorIterator(new ArrayIterator([1, 2]));
$ai->rewind();
var_export($ai->current());
echo '/';
var_export($ai->valid());
echo "\n";
