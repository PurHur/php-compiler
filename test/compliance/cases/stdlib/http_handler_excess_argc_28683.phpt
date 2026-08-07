--TEST--
http_* / get_*_handler excess argc → ArgumentCountError (#28683)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
$cases = [
    static fn () => http_get_last_response_headers('x'),
    static fn () => http_clear_last_response_headers('x'),
    static fn () => get_error_handler('x'),
    static fn () => get_exception_handler('x'),
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
http_clear_last_response_headers();
var_export(http_get_last_response_headers());
echo "\n";
?>
--EXPECT--
http_get_last_response_headers() expects exactly 0 arguments, 1 given
http_clear_last_response_headers() expects exactly 0 arguments, 1 given
get_error_handler() expects exactly 0 arguments, 1 given
get_exception_handler() expects exactly 0 arguments, 1 given
NULL
