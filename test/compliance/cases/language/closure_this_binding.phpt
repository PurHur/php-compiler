--TEST--
language: closures bind $this in object context (issue #4428)
--FILE--
<?php
class C {
    public int $v = 40;
    public function m(): int {
        $h = function () { return $this->v + 2; };
        return $h();
    }
}

echo (new C())->m(), "\n";
--EXPECT--
42

