<?php
$a = ['a' => 1, 'b' => 2];
foreach ($a as &$v) {
    $v = 99;
}
unset($v);
echo json_encode($a), "\n";
