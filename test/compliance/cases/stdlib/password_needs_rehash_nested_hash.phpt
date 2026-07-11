--TEST--
stdlib password_needs_rehash() — nested password_hash() callee agrees with variable-bound form (#17708, ext/standard/password.c)
--FILE--
<?php
$bound = password_needs_rehash(
    password_hash('x', PASSWORD_BCRYPT),
    PASSWORD_BCRYPT,
    ['cost' => 4]
);
$nested = password_needs_rehash(password_hash('x', PASSWORD_BCRYPT), PASSWORD_BCRYPT, ['cost' => 4]);
echo ($bound === $nested && \is_bool($nested)) ? "ok\n" : "fail\n";
try {
    password_needs_rehash('hash', 1.5);
    echo "no_type_error\n";
} catch (\TypeError $e) {
    echo "type_error\n";
}
--EXPECT--
ok
type_error
