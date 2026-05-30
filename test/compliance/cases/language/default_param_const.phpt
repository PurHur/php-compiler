--TEST--
Default parameter: class constant (self::)
--FILE--
<?php
class C {
    public const X = 5;
    public function f(int $a = self::X): int { return $a; }
}
echo (new C)->f(), "\n";
--EXPECT--
5
