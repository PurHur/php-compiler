--TEST--
extends: child method override on instance call (JIT, #101)
--FILE--
<?php
class B {
    public function f(): int {
        return 1;
    }
}
class C extends B {
    public function f(): int {
        return 2;
    }
}
echo (new C())->f();
echo "\n";
--EXPECT--
2
