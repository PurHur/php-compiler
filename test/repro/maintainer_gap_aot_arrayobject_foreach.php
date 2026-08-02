<?php
// Maintainer gap: ArrayObject foreach + count/getArrayCopy under user-script AOT (#26823).
$a = new ArrayObject(['a' => 1, 'b' => 2]);
$a['c'] = 3;
echo $a->count(), "\n";
foreach ($a as $k => $v) {
    echo "$k=$v\n";
}
echo $a->getArrayCopy()['b'], "\n";
