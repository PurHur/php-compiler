<?php

enum E: int {
    case A = 1 + 1;
    case B = 1 << 2;
}

echo E::A->value, "\n", E::B->value, "\n";
