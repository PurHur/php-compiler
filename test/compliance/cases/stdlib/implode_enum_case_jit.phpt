--TEST--
stdlib implode() JIT — backed enum case elements Error (#5581)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
try {
    echo implode(',', [E::A, E::B]), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Object of class E could not be converted to string
