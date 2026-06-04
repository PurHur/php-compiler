<?php
enum E: int { case A = 1; }
switch (1) {
    case E::A:
        echo "match\n";
        break;
    default:
        echo "no\n";
}
