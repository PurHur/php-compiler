--TEST--
header()/http_response_code() excess argc JIT → ArgumentCountError (#30461)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
$cases = [
    static fn () => header('X:1', false, 200, 'extra'),
    static fn () => http_response_code(200, 404),
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
header() expects at most 3 arguments, 4 given
http_response_code() expects at most 1 argument, 2 given
