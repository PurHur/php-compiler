--TEST--
stdlib password_hash/verify(null) — TypeError on 8.4 forward profile (#20174, ext/standard/password.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    'password_hash' => static function (): void {
        password_hash(null, PASSWORD_DEFAULT);
    },
    'password_verify' => static function (): void {
        password_verify(null, 'x');
    },
] as $name => $fn) {
    try {
        $fn();
        echo "$name uncaught\n";
    } catch (TypeError $e) {
        echo $name, ' ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
password_hash password_hash(): Argument #1 ($password) must be of type string, null given
password_verify password_verify(): Argument #1 ($password) must be of type string, null given
