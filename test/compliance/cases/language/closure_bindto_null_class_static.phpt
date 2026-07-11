--TEST--
Language: Closure::bindTo(null, ClassName::class) static scope — self:: reads (#15899, Zend/zend_closures.c)
--FILE--
<?php
declare(strict_types=1);

class C {
    public static int $x = 1;
}

$fn = static function (): int {
    return self::$x;
};

echo ($fn->bindTo(null, C::class))(), "\n";
--EXPECT--
1
