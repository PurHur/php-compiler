--TEST--
Language: array_key_exists/isset/empty on enum case offset throw TypeError (#5562)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }

try {
    var_export(array_key_exists(E::A, [E::A => 1]));
} catch (Throwable $e) {
    echo 'array_key_exists: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    $a = [E::A, E::B];
    var_export(isset($a[E::A]));
} catch (Throwable $e) {
    echo 'isset: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    $a = [E::A, E::B];
    var_export(empty($a[E::A]));
} catch (Throwable $e) {
    echo 'empty: ', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
array_key_exists: TypeError: Illegal offset type
isset: TypeError: Illegal offset type in isset or empty
empty: TypeError: Illegal offset type in isset or empty
