--TEST--
stdlib array_pop()/array_shift()/array_unshift() JIT — E_NOTICE before TypeError on object operand (#15216, ext/standard/array.c)
--FILE--
<?php
$expectedNotice = 'Only variables should be passed by reference';
foreach (['array_pop', 'array_shift', 'array_unshift'] as $fn) {
    try {
        if ('array_unshift' === $fn) {
            $fn(new stdClass(), 1);
        } else {
            $fn(new stdClass());
        }
        echo "{$fn}: uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
    $last = error_get_last();
    echo $fn, '_notice: ', (null !== $last && str_contains($last['message'], $expectedNotice)) ? 'yes' : 'no', "\n";
}
?>
--EXPECTF--
PHP Notice:  Only variables should be passed by reference in %s on line %d
PHP Notice:  Only variables should be passed by reference in %s on line %d
PHP Notice:  Only variables should be passed by reference in %s on line %d
array_pop: array_pop(): Argument #1 ($array) must be of type array, stdClass given
array_pop_notice: yes
array_shift: array_shift(): Argument #1 ($array) must be of type array, stdClass given
array_shift_notice: yes
array_unshift: array_unshift(): Argument #1 ($array) must be of type array, stdClass given
array_unshift_notice: yes
