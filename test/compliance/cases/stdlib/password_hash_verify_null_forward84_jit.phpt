--TEST--
JIT password_hash(null) TypeError on 8.4; password_verify soft-null (#20174/#21314)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
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
    password_hash(null, PASSWORD_DEFAULT);
    echo "password_hash uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    echo 'password_verify=', var_export(password_verify(null, 'x'), true), "\n";
} catch (TypeError $e) {
    echo 'password_verify ', $e->getMessage(), "\n";
}
restore_error_handler();
echo 'depr=', (int) ($seen >= 1), "\n";
?>
--EXPECT--
password_hash(): Argument #1 ($password) must be of type string, null given
password_verify=false
depr=1
