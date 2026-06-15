--TEST--
Runtime: array keys with enum cases must TypeError — not coerce to backing scalar (zend_hash.c, #8768)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }

try {
    $a = [E::A => 'v'];
    echo "literal-fail\n";
} catch (Throwable $e) {
    echo 'literal: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    $a = [];
    $a[E::A] = 1;
    echo "assign-fail\n";
} catch (Throwable $e) {
    echo 'assign: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    $a = [1 => 'x'];
    echo $a[E::A], "\n";
} catch (Throwable $e) {
    echo 'read: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    $arr = [E::B => 2] + [E::A => 1];
    echo "union-fail\n";
} catch (Throwable $e) {
    echo 'union: ', get_class($e), ': ', $e->getMessage(), "\n";
}

enum S: string { case X = 'x'; }
try {
    $a = [S::X => 'v'];
    echo $a[S::X], "\n";
} catch (Throwable $e) {
    echo 'string: ', get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
literal: TypeError: Illegal offset type
assign: TypeError: Illegal offset type
read: TypeError: Illegal offset type
union: TypeError: Illegal offset type
string: TypeError: Illegal offset type
