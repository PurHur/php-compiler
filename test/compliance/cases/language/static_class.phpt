--TEST--
Language: static class (PHP 8.4) parses, reflects, rejects instantiation (#6929)
--FILE--
<?php
static class S {
    public static int $x = 42;
    public static function m(): int {
        return self::$x;
    }
}
echo S::m(), "\n";
$rc = new ReflectionClass(S::class);
echo $rc->isStatic() ? "static\n" : "not-static\n";
try {
    new S();
    echo "instantiated\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
42
static
Cannot instantiate static class S
