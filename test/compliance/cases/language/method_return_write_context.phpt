--TEST--
Language: method return in write context — compile-time fatal (#26436)
--FILE--
<?php
class C {
    public int $x = 1;
    public function &get(): int { return $this->x; }
}
function f(): C { return new C; }
f()->get() = 2;
echo "ASSIGNED\n";
--EXPECT_EXIT--
255
