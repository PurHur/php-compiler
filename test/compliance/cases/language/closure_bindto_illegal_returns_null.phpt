--TEST--
language: illegal Closure::bindTo() returns null after warning (issue #22089, Zend/zend_closures.c)
--SKIPIF--
<?php die('skip — compiler VM compliance via ClosureVMTest/VMTest/JITTest, not Zend CLI'); ?>
--FILE--
<?php
declare(strict_types=1);

class C {
    private int $x = 1;
    public function m(): Closure {
        return function () {
            return $this->x;
        };
    }
}

$f = (new C())->m();
$f2 = $f->bindTo(null, C::class);
echo 'unbind_type=' . gettype($f2) . "\n";

$s = static function () {
    return 1;
};
$s2 = $s->bindTo(new C());
echo 'static_type=' . gettype($s2) . "\n";
--EXPECTF--
PHP Warning:  Cannot unbind $this of closure using $this in %s on line %d
PHP Warning:  Cannot bind an instance to a static closure in %s on line %d
unbind_type=NULL
static_type=NULL
