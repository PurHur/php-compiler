--TEST--
stdlib get_class() — zero-argument form in object scope (#4092, basic_functions.c)
--FILE--
<?php
class GetClassC {
    public function instanceSelf(): void {
        echo get_class(), "\n";
    }
    public static function staticSelf(): void {
        echo get_class(), "\n";
    }
}
class GetClassChild extends GetClassC {}

(new GetClassC())->instanceSelf();
(new GetClassChild())->instanceSelf();
GetClassC::staticSelf();

try {
    get_class();
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
GetClassC
GetClassC
GetClassC
Error
get_class() without arguments must be called from within a class
