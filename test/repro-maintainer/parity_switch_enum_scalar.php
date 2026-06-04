<?php
/** Maintainer repro for #5835 — switch enum case label vs scalar subject (zend_operators.c). */
enum E: int { case A = 1; }
switch (1) {
    case E::A:
        echo "match\n";
        break;
    default:
        echo "no\n";
}
