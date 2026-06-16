--TEST--
Language: array_enum_case_key_spread_union — spread/union enum case keys TypeError (zend_hash.c, #8778, #9014)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }

try {
    $a = [...[E::A => 2]];
    echo "spread-fail\n";
} catch (Throwable $e) {
    echo 'spread: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    $a = [E::B => 2] + [E::A => 1];
    echo "union-fail\n";
} catch (Throwable $e) {
    echo 'union: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    $a = [E::A => 1] + [E::A => 2];
    echo "union-same-fail\n";
} catch (Throwable $e) {
    echo 'union-same: ', get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
spread: TypeError: Illegal offset type
union: TypeError: Illegal offset type
union-same: TypeError: Illegal offset type
