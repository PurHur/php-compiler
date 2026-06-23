--TEST--
stdlib array_diff_uassoc()/array_intersect_uassoc() missing comparator TypeError (#10785, ext/standard/array.c)
--FILE--
<?php
try {
    array_diff_uassoc([1 => 'a'], [1 => 'b']);
    echo "uncaught diff\n";
} catch (TypeError $e) {
    echo 'diff: ', get_class($e), "\n";
}

try {
    array_intersect_uassoc([1 => 'a'], [1 => 'b']);
    echo "uncaught intersect\n";
} catch (TypeError $e) {
    echo 'intersect: ', get_class($e), "\n";
}
?>
--EXPECT--
diff: TypeError
intersect: TypeError
