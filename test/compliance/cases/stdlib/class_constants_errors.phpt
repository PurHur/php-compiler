--TEST--
stdlib class_constants() — missing class and trait errors (issue #7309)
--FILE--
<?php
try {
    $missing = class_constants('Missing7309');
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
trait T7309Err { const Z = 1; }
try {
    $trait = class_constants('T7309Err');
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
Error
Class "Missing7309" not found
Error
Cannot fetch constants from trait T7309Err
