--TEST--
get_called_class() — late-static call-site class name (VM, #3218)
--FILE--
<?php
class CalledClassC {
    public static function staticSelf(): void {
        echo get_called_class(), "\n";
    }
    public function instanceSelf(): void {
        echo get_called_class(), "\n";
    }
}
class CalledClassChild extends CalledClassC {}

CalledClassC::staticSelf();
CalledClassChild::staticSelf();
(new CalledClassChild())->instanceSelf();
(new CalledClassC())->instanceSelf();

try {
    get_called_class();
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
CalledClassC
CalledClassChild
CalledClassChild
CalledClassC
Error
get_called_class() must be called from within a class
