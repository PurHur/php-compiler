--TEST--
php_uname/get_loaded_extensions/getrusage excess argc JIT → ArgumentCountError (#30537)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
foreach ([
    static function () {
        php_uname('s', 'x');
    },
    static function () {
        get_loaded_extensions(false, 1);
    },
    static function () {
        getrusage(0, 1);
    },
] as $fn) {
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
php_uname() expects at most 1 argument, 2 given
get_loaded_extensions() expects at most 1 argument, 2 given
getrusage() expects at most 1 argument, 2 given
