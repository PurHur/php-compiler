--TEST--
PHP 8.1 invokable object first-class callable ((new C)(...)) parity (issue #9605)
--FILE--
<?php
class C {
    public function __invoke(): void {
        echo "ok\n";
    }
}
$fn = (new C)(...);
$fn();
echo $fn instanceof Closure ? "closure\n" : "not-closure\n";
--EXPECT--
ok
closure
