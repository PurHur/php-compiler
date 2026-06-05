--TEST--
stdlib stream I/O JIT — enum case stream operand TypeError (#6170)
--FILE--
<?php
enum E: int { case A = 1; }

try {
    feof(E::A);
    echo "feof: uncaught\n";
} catch (TypeError $e) {
    echo "feof: ", $e->getMessage(), "\n";
}
try {
    fflush(E::A);
    echo "fflush: uncaught\n";
} catch (TypeError $e) {
    echo "fflush: ", $e->getMessage(), "\n";
}
try {
    flock(E::A, LOCK_EX);
    echo "flock: uncaught\n";
} catch (TypeError $e) {
    echo "flock: ", $e->getMessage(), "\n";
}
try {
    fseek(E::A, 0);
    echo "fseek: uncaught\n";
} catch (TypeError $e) {
    echo "fseek: ", $e->getMessage(), "\n";
}
--EXPECT--
feof: feof(): Argument #1 ($stream) must be of type resource, object given
fflush: fflush(): Argument #1 ($stream) must be of type resource, object given
flock: flock(): Argument #1 ($stream) must be of type resource, object given
fseek: fseek(): Argument #1 ($stream) must be of type resource, object given
