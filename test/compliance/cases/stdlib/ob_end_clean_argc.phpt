--TEST--
stdlib ob_end_clean() extra arguments ArgumentCountError (#10323)
--FILE--
<?php
ob_start();
try {
    ob_end_clean(true);
    echo "uncaught\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
ob_end_clean() expects exactly 0 arguments, 1 given
