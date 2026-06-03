--TEST--
language: auto-bound closure reads private property (issue #5325)
--FILE--
<?php
class C {
    private int $p = 42;
    public function make(): Closure {
        return function () {
            return $this->p;
        };
    }
}

echo (new C())->make()(), "\n";
--EXPECT--
42

