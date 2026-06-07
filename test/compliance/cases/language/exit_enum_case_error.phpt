--TEST--
Language: exit()/die() with backed enum case — Error not backing coercion (zend_operators.c, #5805)
--FILE--
<?php
enum E: int { case A = 1; }
try {
    exit(E::A);
} catch (Error $e) {
    echo 'exit:', $e->getMessage(), "\n";
}
try {
    die(E::A);
} catch (Error $e) {
    echo 'die:', $e->getMessage(), "\n";
}
declare(strict_types=1);
try {
    exit(E::A);
} catch (Error $e) {
    echo 'strict-exit:', $e->getMessage(), "\n";
}
try {
    die(E::A);
} catch (Error $e) {
    echo 'strict-die:', $e->getMessage(), "\n";
}
echo "ok\n";
--EXPECT--
exit:Object of class E could not be converted to string
die:Object of class E could not be converted to string
strict-exit:Object of class E could not be converted to string
strict-die:Object of class E could not be converted to string
ok
