--TEST--
Language: enum case array offset read/write/list/literal throw TypeError (#5753, zend_hash.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }

try {
    $a = [];
    $a[E::A] = 1;
    echo "write-fail\n";
} catch (Throwable $e) {
    echo 'write: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    $a = [1 => 'x'];
    $x = $a[E::A];
    echo "read-fail:$x\n";
} catch (Throwable $e) {
    echo 'read: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    [$v] = [E::A => 99];
    echo "list-fail:$v\n";
} catch (Throwable $e) {
    echo 'list: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    $b = [E::A => 3];
    var_export($b);
} catch (Throwable $e) {
    echo 'literal: ', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
write: TypeError: Illegal offset type
read: TypeError: Illegal offset type
list: TypeError: Illegal offset type
literal: TypeError: Illegal offset type
