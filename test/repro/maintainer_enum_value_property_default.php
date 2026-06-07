<?php
enum E: int { case A = 1; }

class C {
    public int $n = E::A->value;
}

var_dump((new C())->n);
