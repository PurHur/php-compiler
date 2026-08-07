--TEST--
array_first/array_last excess argc → ArgumentCountError (#28682)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
$cases = [
    static fn () => array_first([1], 2),
    static fn () => array_first(),
    static fn () => array_last([1], 2),
    static fn () => array_last(),
];
foreach ($cases as $fn) {
    try {
        $fn();
        echo "NO_THROW\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
echo array_first([10, 20]), "\n";
echo array_last([10, 20]), "\n";
?>
--EXPECT--
array_first() expects exactly 1 argument, 2 given
array_first() expects exactly 1 argument, 0 given
array_last() expects exactly 1 argument, 2 given
array_last() expects exactly 1 argument, 0 given
10
20
