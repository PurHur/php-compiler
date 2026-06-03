<?php
$a = [1, 2, 3];
foreach ($a as &$v) {
    $v *= 10;
}
unset($v);
var_export($a);
