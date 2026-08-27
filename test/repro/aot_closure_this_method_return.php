<?php
// AOT: method-returned closure reading $this (#35456).
class C {
    private $x = 3;
    public function f() {
        return function () {
            return $this->x;
        };
    }
}
$c = new C;
$f = $c->f();
echo $f(), PHP_EOL;
