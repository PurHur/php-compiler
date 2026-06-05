<?php
enum E: int {
    case A = 1;
    public const int FOO = 2;
}
echo E::FOO, "\n";
