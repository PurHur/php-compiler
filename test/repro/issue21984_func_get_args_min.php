<?php
function f($a) {
    $a = 99;
    $args = func_get_args();
    echo $args[0], "\n";
}
f(1);
