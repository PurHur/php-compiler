<?php
class D {
    public string $x = 'ok';
    public function m(): string {
        return $this->x;
    }
}
$d = new D();
$c = $d->m(...);
echo $c(), "\n";

