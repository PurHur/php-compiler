--TEST--
PHP 8.4 static asymmetric visibility: external write Error (#6769, zend_compile.c)
--FILE--
<?php
class PrivateSetStatic {
    private(set) static string $name = 'x';

    public static function mutate(): void {
        self::$name = 'y';
    }
}

class ProtectedSetStatic {
    protected(set) static string $tag = 'a';

    public static function mutate(): void {
        self::$tag = 'b';
    }
}

try {
    PrivateSetStatic::$name = 'bad';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo PrivateSetStatic::$name, "\n";
PrivateSetStatic::mutate();
echo PrivateSetStatic::$name, "\n";

try {
    ProtectedSetStatic::$tag = 'bad';
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo ProtectedSetStatic::$tag, "\n";
ProtectedSetStatic::mutate();
echo ProtectedSetStatic::$tag, "\n";
--EXPECT--
Error: Cannot modify private(set) property PrivateSetStatic::$name from global scope
x
y
Error: Cannot modify protected(set) property ProtectedSetStatic::$tag from global scope
a
b
