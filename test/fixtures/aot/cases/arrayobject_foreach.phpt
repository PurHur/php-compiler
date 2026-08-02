--TEST--
ArrayObject foreach + count/getArrayCopy AOT (#26823)
--FILE--
<?php
$a = new ArrayObject(['a' => 1, 'b' => 2]);
$a['c'] = 3;
echo $a->count(), "\n";
foreach ($a as $k => $v) {
    echo "$k=$v\n";
}
echo $a->getArrayCopy()['b'], "\n";
--EXPECT--
3
a=1
b=2
c=3
2
