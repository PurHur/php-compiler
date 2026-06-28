--TEST--
stdlib sort()/ksort()/arsort()/natcasesort() — E_NOTICE before TypeError on non-variable operand (#13234, ext/standard/array.c)
--FILE--
<?php
$expectedNotice = 'Only variables should be passed by reference';
foreach (['sort', 'ksort'] as $fn) {
    try {
        $fn(new stdClass());
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
sort: sort(): Argument #1 ($array) must be of type array, stdClass given
sort_notice: yes
ksort: ksort(): Argument #1 ($array) must be of type array, stdClass given
ksort_notice: yes
