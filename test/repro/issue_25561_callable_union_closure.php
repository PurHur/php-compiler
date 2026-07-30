<?php
/** #25561 — callable|string accepts Closure (FCC + anonymous); Zend/zend_execute.c */
function f(callable|string $c): string {
    if (is_string($c)) {
        return 'str';
    }

    return 'fn:' . $c(2);
}

echo f('strlen'), "\n";
echo f(strlen(...)), "\n";
echo f(function ($x) { return $x; }), "\n";
