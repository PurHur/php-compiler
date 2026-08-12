--TEST--
headers_list/getlastmod excess argc JIT → ArgumentCountError (#30417)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
$cases = [
    static fn () => headers_list(null),
    static fn () => getlastmod(null),
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
headers_list() expects exactly 0 arguments, 1 given
getlastmod() expects exactly 0 arguments, 1 given
