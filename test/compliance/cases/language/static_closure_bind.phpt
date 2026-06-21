--TEST--
language: static closure bindTo/bind warn and no-op (issue #10432, Zend/zend_closures.c)
--SKIPIF--
<?php die('skip — compiler VM compliance via ClosureVMTest/VMTest/JITTest, not Zend CLI'); ?>
--FILE--
<?php
declare(strict_types=1);

class C {
    public int $x = 1;
    public function make(): Closure {
        return static function () {
            return 0;
        };
    }
}

$c = new C();
$fn = $c->make();
$fn->bindTo($c);
echo "bindTo ok\n";

Closure::bind($fn, $c, 'C');
echo "bind ok\n";

$unbound = $fn->bindTo(null);
echo $unbound === null ? "null\n" : "object\n";
--EXPECT--
PHP Warning:  Cannot bind an instance to a static closure
PHP Warning:  Cannot bind an instance to a static closure
bindTo ok
bind ok
object
