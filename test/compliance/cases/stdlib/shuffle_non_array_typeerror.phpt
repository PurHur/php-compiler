--TEST--
stdlib shuffle() — E_NOTICE before TypeError on non-array operand (#13355, ext/standard/array.c)
--FILE--
<?php
$expectedNotice = 'Only variables should be passed by reference';
try {
    shuffle(new stdClass());
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
$last = error_get_last();
echo 'notice: ', (null !== $last && str_contains($last['message'], $expectedNotice)) ? 'yes' : 'no', "\n";
?>
--EXPECTF--
PHP Notice:  Only variables should be passed by reference in %s on line %d
shuffle(): Argument #1 ($array) must be of type array, stdClass given
notice: yes
