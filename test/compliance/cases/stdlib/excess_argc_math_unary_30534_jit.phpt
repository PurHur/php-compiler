--TEST--
unary math + pi() excess argc JIT → ArgumentCountError (#30534)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
$cases = [
    static fn () => pi(1),
    static fn () => sqrt(4, 1),
    static fn () => sin(0, 1),
    static fn () => asinh(0, 1),
    static fn () => deg2rad(1, 2),
    static fn () => log10(10, 1),
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
?>
--EXPECT--
pi() expects exactly 0 arguments, 1 given
sqrt() expects exactly 1 argument, 2 given
sin() expects exactly 1 argument, 2 given
asinh() expects exactly 1 argument, 2 given
deg2rad() expects exactly 1 argument, 2 given
log10() expects exactly 1 argument, 2 given
