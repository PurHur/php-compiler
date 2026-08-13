--TEST--
idate() excess argc → ArgumentCountError (#30543)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    var_export(idate('Y', time(), 1));
    echo "\nNO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    idate();
    echo "NO_THROW0\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo idate('Y', strtotime('2020-06-15 12:00:00')), "\n";
?>
--EXPECT--
idate() expects at most 2 arguments, 3 given
idate() expects at least 1 argument, 0 given
2020
