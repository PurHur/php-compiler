--TEST--
Language: trait method first-class callable (new C)->f(...) (#9604, zend_compile.c)
--FILE--
<?php
trait T {
    public function f(): void {
        echo "ok\n";
    }
}
class C {
    use T;
}
$fn = (new C)->f(...);
$fn();
--EXPECT--
ok
