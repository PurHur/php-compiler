--TEST--
ArrayIterator has no arsort/krsort — Zend undefined (#22594, ext/spl/spl_array.stub.php)
--FILE--
<?php
$it = new ArrayIterator(['b' => 2, 'a' => 1]);
$rai = new RecursiveArrayIterator(['b' => 2, 'a' => 1]);
$ao = new ArrayObject(['b' => 2, 'a' => 1]);
foreach (['arsort', 'krsort'] as $m) {
    echo $m, ' method_exists=', method_exists($it, $m) ? '1' : '0', "\n";
    echo $m, ' rai=', method_exists($rai, $m) ? '1' : '0', "\n";
    echo $m, ' ao=', method_exists($ao, $m) ? '1' : '0', "\n";
}
try {
    $it->arsort();
    echo "arsort ran\n";
} catch (Error $e) {
    echo "arsort: Error\n";
}
try {
    $it->krsort();
    echo "krsort ran\n";
} catch (Error $e) {
    echo "krsort: Error\n";
}
echo 'asort ok=', method_exists($it, 'asort') ? '1' : '0', "\n";
echo 'ksort ok=', method_exists($it, 'ksort') ? '1' : '0', "\n";
?>
--EXPECT--
arsort method_exists=0
arsort rai=0
arsort ao=0
krsort method_exists=0
krsort rai=0
krsort ao=0
arsort: Error
krsort: Error
asort ok=1
ksort ok=1
