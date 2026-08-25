<?php
// #34635 — foreach string-key borrow: empty body must not free HT keys.
$a = ['a' => 1, 'b' => 2, 'c' => 3];
foreach ($a as $k => $v) {
}
var_dump($a);
