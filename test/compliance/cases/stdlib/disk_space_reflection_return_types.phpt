--TEST--
stdlib disk_* Reflection return types include false (php-src basic_functions.stub.php, #28521)
--FILE--
<?php
foreach (['disk_free_space', 'disk_total_space'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ': ', (string) $r->getReturnType(), "\n";
}
?>
--EXPECT--
disk_free_space: float|false
disk_total_space: float|false
