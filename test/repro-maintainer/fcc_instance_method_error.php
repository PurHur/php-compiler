<?php
class C {
    public function m(): int { return 1; }
}
$f = C::m(...);
var_dump($f());
