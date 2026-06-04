<?php
function f($x) {
    return $x;
}
$a = 1;
echo f(&$a), "\n";
