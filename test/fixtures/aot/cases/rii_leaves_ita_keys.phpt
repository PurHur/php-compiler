--TEST--
AOT: RecursiveIteratorIterator LEAVES_ONLY iterator_to_array key overwrite (#27257)
--FILE--
<?php
$it = new RecursiveArrayIterator([1, [2, 3]]);
$flat = iterator_to_array(new RecursiveIteratorIterator($it));
echo implode(',', $flat), "\n";
$out = [];
foreach (new RecursiveIteratorIterator(new RecursiveArrayIterator([1, [2, 3]])) as $v) {
    $out[] = $v;
}
echo implode(',', $out), "\n";
--EXPECT--
2,3
1,2,3
