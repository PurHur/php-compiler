<?php
class C {
    public int $p {
        get => $this->p * 2;
        set => $value;
    }
}
$c = new C();
$c->p = 3;
echo $c->p, "\n";
