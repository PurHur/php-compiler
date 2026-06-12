--TEST--
stdlib get_class_methods() — extra argument ArgumentCountError (#4786, basic_functions.c)
--FILE--
<?php
class C {
    public function a(): void {}
}
try {
    get_class_methods(new C(), false);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
ArgumentCountError: get_class_methods() expects exactly 1 argument, 2 given
