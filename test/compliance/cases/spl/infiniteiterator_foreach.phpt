--TEST--
InfiniteIterator foreach cycles (#27568)
--FILE--
<?php
$it = new InfiniteIterator(new ArrayIterator([1, 2]));
$n = 0;
$out = [];
foreach ($it as $v) {
    $out[] = $v;
    if (++$n >= 5) {
        break;
    }
}
echo implode(',', $out), PHP_EOL;
--EXPECT--
1,2,1,2,1
