<?php
/**
 * #34457 — named string args + echo $a.$b must not segfault under AOT.
 * Rematerializing via fromLiteral crashed; reuse live ARG_SEND Variables.
 */
function s($a, $b) {
    echo $a.$b;
}
s(b:"y", a:"x");
echo "\n";
s(a:"x", b:"y");
echo "\n";
s("x", b:"Y");
echo "\n";
s("x", "y");
echo "\n";
function add($a, $b) {
    echo $a + $b;
}
add(b:2, a:1);
echo "\n";
