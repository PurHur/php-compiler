--TEST--
getenv/putenv/ini helpers/error_reporting/trigger_error excess argc → ArgumentCountError (#28690)
--FILE--
<?php
error_reporting(E_ALL);
$cases = [
    static fn () => getenv('PATH', true, 'x'),
    static fn () => putenv('A=1', 'x'),
    static fn () => ini_get('memory_limit', 'x'),
    static fn () => ini_set('display_errors', '1', 'x'),
    static fn () => error_reporting(E_ALL, 'x'),
    static fn () => trigger_error('x', E_USER_NOTICE, 'y'),
    static fn () => trigger_error(),
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
getenv() expects at most 2 arguments, 3 given
putenv() expects exactly 1 argument, 2 given
ini_get() expects exactly 1 argument, 2 given
ini_set() expects exactly 2 arguments, 3 given
error_reporting() expects at most 1 argument, 2 given
trigger_error() expects at most 2 arguments, 3 given
trigger_error() expects at least 1 argument, 0 given
