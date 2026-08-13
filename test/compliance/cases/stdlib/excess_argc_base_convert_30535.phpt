--TEST--
dechex/decoct/decbin/octdec excess argc → ArgumentCountError (#30535)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$cases = [
    static fn () => dechex(10, 1),
    static fn () => decoct(10, 1),
    static fn () => decbin(10, 1),
    static fn () => octdec('12', 1),
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
dechex() expects exactly 1 argument, 2 given
decoct() expects exactly 1 argument, 2 given
decbin() expects exactly 1 argument, 2 given
octdec() expects exactly 1 argument, 2 given
