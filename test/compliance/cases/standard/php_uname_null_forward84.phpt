--TEST--
stdlib php_uname(null) TypeError on 8.4 forward profile (#19201, ext/standard/info.c)
--FILE--
<?php
putenv('PHP_COMPILER_PROFILE=8.4');
$_ENV['PHP_COMPILER_PROFILE'] = '8.4';
$_SERVER['PHP_COMPILER_PROFILE'] = '8.4';
try {
    php_uname(null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
php_uname(): Argument #1 ($mode) must be of type string, null given
