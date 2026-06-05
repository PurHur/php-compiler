--TEST--
Language: uninitialized static typed property read — JIT/AOT (#5047)
--SKIPIF--
<?php
if (!getenv('PHP_COMPILER_JIT_EXECUTE')) {
    die('skip JIT execute not enabled');
}
--FILE--
<?php
class C {
    public static int $x;
}

function read_x(): void {
    echo C::$x, "\n";
}

try {
    read_x();
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
C::$x = 1;
read_x();
--EXPECT--
Typed static property C::$x must not be accessed before initialization
1
