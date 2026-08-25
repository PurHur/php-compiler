<?php
// AOT: by-ref Closure return of use (&$a) must alias (#34759 / re-#34717).
$a = 1;
$f = function &() use (&$a) {
    return $a;
};
$r = &$f();
$r = 3;
var_dump($a);
