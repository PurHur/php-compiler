--TEST--
stdlib fopen excess argc ACE at most 4 (#30574)
--FILE--
<?php
try {
    fopen(__FILE__, 'r', false, null, 'extra');
} catch (Throwable $e) {
    echo 'excess ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    fopen(__FILE__);
} catch (Throwable $e) {
    echo 'missing ', get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
excess ArgumentCountError: fopen() expects at most 4 arguments, 5 given
missing ArgumentCountError: fopen() expects at least 2 arguments, 1 given
