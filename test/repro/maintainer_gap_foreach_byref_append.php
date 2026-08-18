<?php
// #32128 — foreach-by-ref append during iteration must not leave IS_REFERENCE on iterated slots
$arr = [1, 2];
foreach ($arr as &$v) {
    if ($v === 2) {
        $arr[] = 3;
    }
}
unset($v);
var_dump($arr);
