--TEST--
AOT: password_hash/implode soft-null on 8.4 (#21210)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$p = null;
try {
    $h = password_hash($p, PASSWORD_DEFAULT);
    echo (false === $h || is_string($h)) ? "password_hash OK\n" : "password_hash BAD\n";
} catch (TypeError $e) {
    echo "password_hash TypeError\n";
}
$sep = null;
echo 'ab' === implode($sep, ['a', 'b']) ? "implode OK\n" : "implode BAD\n";
--EXPECT--
password_hash OK
implode OK
