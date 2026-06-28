--TEST--
SPL ParentIterator/AppendIterator/CallbackFilterIterator rewind (#13211, ext/spl/spl_iterators.c)
--FILE--
<?php
$p = new ParentIterator(new RecursiveArrayIterator([1, [2, 3]]));
$p->rewind();
echo $p->valid() ? 'parent-valid' : 'parent-invalid', "\n";

$app = new AppendIterator();
$app->rewind();
echo $app->valid() ? 'append-valid' : 'append-invalid', "\n";

$cb = new CallbackFilterIterator(new ArrayIterator([]), fn ($v) => true);
$cb->rewind();
echo $cb->valid() ? 'cb-valid' : 'cb-invalid', "\n";
--EXPECT--
parent-valid
append-invalid
cb-invalid
