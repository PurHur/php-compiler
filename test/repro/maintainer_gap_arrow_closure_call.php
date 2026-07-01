<?php
class C {
    public function m(): int { return 2; }

    public function viaCall(): int {
        $fn = fn() => $this->m();
        return $fn->call($this);
    }
}
$c = new C();
$fn = fn() => $this->m();
var_dump($fn->call($c));
echo $c->viaCall(), "\n";
