--TEST--
CallbackFilterIterator AOT Closure filter (#27259)
--FILE--
<?php
$it = new CallbackFilterIterator(new ArrayIterator([1, 2, 3, 4]), fn($v) => $v % 2 === 0);
echo implode(',', iterator_to_array($it)), "\n";
foreach ($it as $v) {
    echo $v, ',';
}
echo "\n";
--EXPECT--
2,4
2,4,
