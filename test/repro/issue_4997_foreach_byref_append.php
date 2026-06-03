<?php
$c = [1, 2, 3];
foreach ($c as $k => &$v) {
    if ($k === 0) {
        $c[] = 4;
    }
}
unset($v);
var_export($c);
