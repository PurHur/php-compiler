--TEST--
stdlib array_combine() — enum case keys must Error under JIT (ext/standard/array.c #5538)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
function run(): void {
    try {
        array_combine([E::A, E::B], [10, 20]);
        echo "no error\n";
    } catch (Error $e) {
        echo $e->getMessage(), "\n";
    }
}
run();
--EXPECT--
Object of class E could not be converted to string
