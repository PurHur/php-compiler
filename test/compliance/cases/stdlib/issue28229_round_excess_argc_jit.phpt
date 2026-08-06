--TEST--
round() excess argc ArgumentCountError under JIT (#28229)
--FILE--
<?php
try {
    round(1.5, 0, 1, true);
    echo "ran\n";
} catch (ArgumentCountError $e) {
    echo 'ArgumentCountError: ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo round(2.5, 0, PHP_ROUND_HALF_UP), "\n";
?>
--EXPECT--
ArgumentCountError: round() expects at most 3 arguments, 4 given
3
