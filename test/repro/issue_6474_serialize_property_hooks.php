<?php

class C {
    private string $x = 'secret';
    public string $y { get => $this->x; set => $this->x = $value; }
}
$c = new C();
$s = serialize($c);
var_dump($s);
$u = unserialize($s);
var_dump($u instanceof C, $u->y);
