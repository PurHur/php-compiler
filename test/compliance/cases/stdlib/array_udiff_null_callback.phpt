--TEST--
stdlib array_udiff()/array_udiff_assoc()/array_diff_ukey() null callback — TypeError (#10799, ext/standard/array.c)
--FILE--
<?php
foreach (['array_udiff', 'array_udiff_assoc', 'array_diff_ukey'] as $fn) {
    try {
        $fn([1], [2], null);
    } catch (Throwable $e) {
        echo $fn, ': ', get_class($e), "\n";
    }
}
?>
--EXPECT--
array_udiff: TypeError
array_udiff_assoc: TypeError
array_diff_ukey: TypeError
