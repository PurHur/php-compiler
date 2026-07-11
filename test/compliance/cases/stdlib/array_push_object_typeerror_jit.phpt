--TEST--
stdlib array_push() JIT — E_NOTICE before TypeError on object operand (#15217, ext/standard/array.c)
--FILE--
<?php
$expectedNotice = 'Only variables should be passed by reference';
try {
    array_push(new stdClass(), 1);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
$last = error_get_last();
echo 'notice: ', (null !== $last && str_contains($last['message'], $expectedNotice)) ? 'yes' : 'no', "\n";
?>
--EXPECTF--
PHP Notice:  Only variables should be passed by reference in %s on line %d
array_push(): Argument #1 ($array) must be of type array, stdClass given
notice: yes
