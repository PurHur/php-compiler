<?php
class C {
    private int $p = 42;
    public function make(): Closure {
        return function () {
            return $this->p;
        };
    }
}

$v = (new C())->make()();
echo $v, "\n";
