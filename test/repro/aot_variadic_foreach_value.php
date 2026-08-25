<?php
// AOT: foreach (by value) over ...$args (#34684)
function f(...$args) {
    $s = 0;
    foreach ($args as $a) {
        $s += $a;
    }
    echo $s, "\n";
}
f(1, 2, 3);
