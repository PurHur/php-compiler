--TEST--
Language: instance method first-class callable on (new C)->m(...) (#9604, zend_compile.c)
--FILE--
<?php
class C {
    public function f(): void {
        echo "ok\n";
    }
}
$fn = (new C)->f(...);
$fn();
--EXPECT--
ok
