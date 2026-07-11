--TEST--
stdlib array_walk() — E_NOTICE only for non-variable object operand (#13237, ext/standard/array.c)
--FILE--
<?php
$expectedNotice = 'Only variables should be passed by reference';
try {
    $ok = array_walk(new stdClass(), static fn () => null);
    echo $ok ? "walk: true\n" : "walk: false\n";
} catch (Throwable $e) {
    echo 'walk: ', get_class($e), ': ', $e->getMessage(), "\n";
}
$last = error_get_last();
echo 'notice: ', (null !== $last && str_contains($last['message'], $expectedNotice)) ? 'yes' : 'no', "\n";
?>
--EXPECTF--
PHP Notice:  Only variables should be passed by reference in %s on line %d
walk: true
notice: yes
