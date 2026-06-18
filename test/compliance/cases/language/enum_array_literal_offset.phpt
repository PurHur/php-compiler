--TEST--
Language: array literal with enum case offset throws TypeError (#9186)
--FILE--
<?php
enum E: int { case A = 1; }

try {
    $a = [E::A => 'v'];
    echo "accepted\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: Illegal offset type

