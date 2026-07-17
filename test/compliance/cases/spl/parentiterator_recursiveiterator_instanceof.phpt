--TEST--
ParentIterator/RecursiveRegexIterator instanceof RecursiveIterator + RII (#19784)
--FILE--
<?php
$arr = new RecursiveArrayIterator(['a' => 1, 'b' => [2, 3], 'c' => 4]);
$pi = new ParentIterator($arr);
echo (int) ($pi instanceof RecursiveIterator), "\n";
$impl = class_implements($pi);
echo (int) (is_array($impl) && isset($impl['RecursiveIterator'])), "\n";
echo (int) (is_array($impl) && isset($impl['OuterIterator'])), "\n";

$it = new RecursiveIteratorIterator($pi, RecursiveIteratorIterator::SELF_FIRST);
$out = [];
foreach ($it as $k => $v) {
    $out[] = [$k, is_array($v) ? 'arr' : $v];
}
echo json_encode($out), "\n";

$ch = (new ParentIterator(new RecursiveArrayIterator(['b' => [2, 3]])));
foreach ($ch as $_) {
    echo get_class($ch->getChildren()), "\n";
}

$rx = new RecursiveRegexIterator(new RecursiveArrayIterator(['a' => 1, 'b' => [2]]), '/./');
echo (int) ($rx instanceof RecursiveIterator), "\n";
$rimpl = class_implements($rx);
echo (int) (is_array($rimpl) && isset($rimpl['RecursiveIterator'])), "\n";
new RecursiveIteratorIterator($rx);
echo "rx_rii_ok\n";
?>
--EXPECT--
1
1
1
[["b","arr"]]
ParentIterator
1
1
rx_rii_ok
