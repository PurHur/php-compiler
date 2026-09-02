<?php
$a = ['a' => 1, 'b' => 2];
foreach ($a as &$v) {
    $v = 99;
    echo "v=$v,";
}
unset($v);
echo '|a=' . json_encode($a), "\n";
