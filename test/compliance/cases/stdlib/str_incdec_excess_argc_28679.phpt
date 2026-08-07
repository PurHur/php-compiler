--TEST--
str_increment/str_decrement excess argc → ArgumentCountError (#28679)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$cases = [
    static fn () => str_increment('a', 'x'),
    static fn () => str_increment(),
    static fn () => str_decrement('b', 'x'),
    static fn () => str_decrement(),
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
echo str_increment('a'), "\n";
echo str_decrement('b'), "\n";
?>
--EXPECT--
str_increment() expects exactly 1 argument, 2 given
str_increment() expects exactly 1 argument, 0 given
str_decrement() expects exactly 1 argument, 2 given
str_decrement() expects exactly 1 argument, 0 given
b
a
