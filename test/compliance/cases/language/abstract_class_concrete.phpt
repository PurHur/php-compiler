--TEST--
abstract class concrete subclass (issue #144)
--FILE--
<?php
abstract class A {
    abstract public function f(): int;
}
class C extends A {
    public function f(): int { return 42; }
}
echo (new C)->f(), "\n";
--EXPECT--
42
