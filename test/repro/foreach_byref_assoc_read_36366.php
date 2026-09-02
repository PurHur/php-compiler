<?php
$a = ['a' => 1, 'b' => 2];
foreach ($a as &$v) {
    echo $v;
}
echo "\n";
