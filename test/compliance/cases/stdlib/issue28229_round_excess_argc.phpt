--TEST--
round() excess argc raises ArgumentCountError not LogicException (#28229, Zend math.c)
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
try {
    round();
    echo "zero ran\n";
} catch (ArgumentCountError $e) {
    echo 'zero ArgumentCountError: ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'zero ', get_class($e), ': ', $e->getMessage(), "\n";
}
echo round(1.5, 0, 1), "\n";
?>
--EXPECT--
ArgumentCountError: round() expects at most 3 arguments, 4 given
zero ArgumentCountError: round() expects at least 1 argument, 0 given
2
