<?php
// AOT: foreach over &...$args (and ...$args) must match Zend (#34684; residual of #27407/#24167)
function f(&...$args) {
    foreach ($args as &$a) {
        $a *= 2;
    }
}
$x = 1;
$y = 2;
f($x, $y);
echo $x, $y, "\n";
