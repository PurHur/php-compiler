--TEST--
stdlib password_needs_rehash(null) soft-null on 8.4 forward (#21314, re-#18655)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$seen = 0;
set_error_handler(static function (int $no) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen++;
    }
    return true;
});
try {
    echo var_export(password_needs_rehash(null, PASSWORD_DEFAULT), true), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
restore_error_handler();
echo 'depr=', (int) ($seen >= 1), "\n";
?>
--EXPECT--
true
depr=1
