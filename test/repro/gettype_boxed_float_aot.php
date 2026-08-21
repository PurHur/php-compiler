<?php
/** Issue #33398 — AOT gettype() on boxed float must not SIGSEGV. */
$cases = [
    PHP_INT_MAX + 1,
    9.223372036854776E+18,
    1.5,
];
foreach ($cases as $x) {
    echo gettype($x), "\n";
}
