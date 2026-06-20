--TEST--
language: static closure bindTo/bind must Error (issue #4613, Zend/zend_closures.c)
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
try {
    $fn->bindTo($c);
    echo "bindTo ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    Closure::bind($fn, $c, 'C');
    echo "bind ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

$unbound = $fn->bindTo(null);
echo $unbound === null ? "null\n" : "object\n";
--EXPECT--
Error: Cannot bind static closure to object
Error: Cannot bind static closure to object
object
