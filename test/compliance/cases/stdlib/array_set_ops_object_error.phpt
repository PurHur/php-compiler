--TEST--
Stdlib: array_intersect()/array_diff*() object elements throw Error (VM, #11249, ext/standard/array.c)
--FILE--
<?php
$o = new stdClass();
foreach (['array_intersect', 'array_diff', 'array_diff_assoc'] as $fn) {
    try {
        $fn([$o], [$o]);
        echo $fn, ": no throw\n";
    } catch (Throwable $e) {
        echo $fn, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
array_intersect: Error: Object of class stdClass could not be converted to string
array_diff: Error: Object of class stdClass could not be converted to string
array_diff_assoc: Error: Object of class stdClass could not be converted to string
