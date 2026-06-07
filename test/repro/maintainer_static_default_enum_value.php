<?php
enum E: int { case A = 1; }

class C {
    public static int $s = E::A->value;
}

var_dump(C::$s);
