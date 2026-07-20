--TEST--
stdlib password_verify/needs_rehash(null) soft-null on 8.4; hash_equals TypeError (#21314)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen[] = $str;
    }
    return true;
});
try {
    hash_equals(null, 'x');
    echo "hash_equals:OK\n";
} catch (TypeError $e) {
    echo "hash_equals:TE\n";
}
try {
    echo 'password_verify=', var_export(password_verify(null, 'x'), true), "\n";
} catch (TypeError $e) {
    echo "password_verify:TE\n";
}
try {
    echo 'password_needs_rehash=', var_export(password_needs_rehash(null, PASSWORD_DEFAULT), true), "\n";
} catch (TypeError $e) {
    echo "password_needs_rehash:TE\n";
}
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 2), "\n";
?>
--EXPECT--
hash_equals:TE
password_verify=false
password_needs_rehash=true
depr=1
