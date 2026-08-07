--TEST--
Language: $a[] after PHP_INT_MAX key throws Error (zend_hash.c / #28762)
--FILE--
<?php
try {
    $a = [PHP_INT_MAX => 'x'];
    $a[] = 'y';
    echo 'fail count=', count($a), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

$b = [PHP_INT_MAX - 1 => 'a'];
$b[] = 'b';
echo 'control count=', count($b), ' last=', array_key_last($b), "\n";
?>
--EXPECT--
Error: Cannot add element to the array as the next element is already occupied
control count=2 last=9223372036854775807
