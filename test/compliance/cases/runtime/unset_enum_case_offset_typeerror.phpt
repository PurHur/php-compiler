--TEST--
Runtime: unset() with enum case offset must TypeError — not coerce to backing scalar (#9969, zend_hash.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }

$a = [1 => 10, 2 => 20];
try {
    unset($a[E::A]);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

$b = [1 => 10, 2 => 20];
unset($b[1]);
echo 'int-unset: ', count($b), "\n";
?>
--EXPECT--
Illegal offset type in unset
int-unset: 1
