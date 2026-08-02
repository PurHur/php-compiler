--TEST--
AOT: RecursiveIteratorIterator foreach LEAVES_ONLY flatten (#26775)
--FILE--
<?php
$arr = ['a' => [1, 2], 'b' => [3]];
$it = new RecursiveArrayIterator($arr);
$flat = new RecursiveIteratorIterator($it);
$out = [];
foreach ($flat as $v) {
    $out[] = $v;
}
echo implode(',', $out), "\n";
--EXPECT--
1,2,3
