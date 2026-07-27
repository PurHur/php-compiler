<?php
// AOT construct probe #23886 — ArrayIterator methods/foreach remain broken under AOT on master
// (undefined append/rewind; foreach property fetch). Prove ArrayObject ctor no longer TypeErrors.
$ao = new ArrayObject(['a' => 1, 'b' => 2]);
$it = new ArrayIterator($ao);
echo $it instanceof ArrayIterator ? "constructed\n" : "fail\n";
