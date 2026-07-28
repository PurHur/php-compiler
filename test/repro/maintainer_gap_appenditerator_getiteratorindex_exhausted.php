<?php
$a = new AppendIterator();
$a->append(new ArrayIterator([1, 2]));
$a->append(new ArrayIterator([3]));
foreach ($a as $v) {
}
echo "idx=", var_export($a->getIteratorIndex(), true), " valid=", $a->valid() ? "1" : "0", "\n";
