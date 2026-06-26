--TEST--
stdlib password_hash() — out-of-range bcrypt cost ValueError (#12232, ext/standard/password.c)
--FILE--
<?php
try {
    password_hash('password', PASSWORD_BCRYPT, ['cost' => 3]);
    echo "no exception\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Invalid bcrypt cost parameter specified: 3
