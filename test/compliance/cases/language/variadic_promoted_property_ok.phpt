--TEST--
Language: non-variadic promotion and non-promoted variadic still work (#26515)
--FILE--
<?php
class A {
    public function __construct(public int $x) {}
}
class B {
    public function __construct(int ...$x) {
        echo count($x), "\n";
    }
}
echo (new A(7))->x, "\n";
new B(1, 2);
--EXPECT--
7
2
