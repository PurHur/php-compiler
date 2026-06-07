<?php
enum E: int {
    case A = 1;
    case B = self::A->value + 1;
}
echo E::B->value, "\n";
