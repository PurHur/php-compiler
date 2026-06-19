--TEST--
language: Closure::fromStatic() static method callable (#9992, Zend/zend_closures.c)
--FILE--
<?php
declare(strict_types=1);

class C {
    public static function m(): string {
        return 'hi';
    }

    public static function add(int $a, int $b): int {
        return $a + $b;
    }
}

$c = Closure::fromStatic(C::class . '::m');
var_export($c());
echo "\n";

$add = Closure::fromStatic(C::class . '::add');
var_export($add(2, 3));
echo "\n";

try {
    Closure::fromStatic('strlen');
} catch (TypeError $e) {
    echo 'TypeError:', $e->getMessage(), "\n";
}
?>
--EXPECT--
'hi'
5
TypeError:Closure::fromStatic(): Argument #1 ($callable) must be a valid callback
