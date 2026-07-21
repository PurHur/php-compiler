--TEST--
stdlib setcookie(null $expires) deprecation cites parameter #3 JIT (#21735, ext/standard/head.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo $msg, "\n";

        return true;
    }

    return false;
});
$expires = null;
@setcookie('n', 'v', $expires);
--EXPECT--
setcookie(): Passing null to parameter #3 ($expires_or_options) of type array|int is deprecated
