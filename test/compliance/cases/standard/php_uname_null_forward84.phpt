--TEST--
stdlib php_uname(null) soft-null then ValueError on 8.4 forward profile (#28136, Zend 8.4)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    php_uname(null);
    echo "fail\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
} catch (TypeError $e) {
    echo "TypeError:", $e->getMessage(), "\n";
}
?>
--EXPECT--
php_uname(): Argument #1 ($mode) must be a single character
