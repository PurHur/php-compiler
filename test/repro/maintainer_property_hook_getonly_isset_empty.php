<?php

class C {
    public int $x {
        get => 1;
    }
}

$c = new C();
var_dump(isset($c->x));
var_dump(empty($c->x));
