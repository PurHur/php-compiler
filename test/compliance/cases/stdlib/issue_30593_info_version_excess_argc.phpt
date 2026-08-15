--TEST--
extension_loaded/phpinfo/version_compare excess argc → ArgumentCountError (#30593)
--FILE--
<?php
foreach ([
    static function () {
        extension_loaded('standard', 'x');
    },
    static function () {
        phpinfo(INFO_GENERAL, 'x');
    },
    static function () {
        version_compare('1', '2', '<', 'x');
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
extension_loaded() expects exactly 1 argument, 2 given
phpinfo() expects at most 1 argument, 2 given
version_compare() expects at most 3 arguments, 4 given
