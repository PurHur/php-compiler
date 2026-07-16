<?php
$a = new AppendIterator();
$a->append(new ArrayIterator([1, 2]));
$a->append(new ArrayIterator([3]));
$a->rewind();
$a->next();
$a->next();
$index = $a->getIteratorIndex();
$count = $a->getArrayIterator()->count();
echo ($index === 1 && $count === 2) ? "ok\n" : ('fail: index=' . $index . ' count=' . $count . "\n");
