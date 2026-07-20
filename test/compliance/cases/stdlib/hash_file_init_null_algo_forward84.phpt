--TEST--
stdlib hash_file()/hash_init() null $algo DEP+ValueError on 8.4 (#21572, ext/hash/hash.c)
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
    hash_file(null, '/etc/hosts');
    echo "file:OK\n";
} catch (ValueError $e) {
    echo "file:VE\n";
} catch (TypeError $e) {
    echo "file:TE\n";
}
try {
    hash_init(null);
    echo "init:OK\n";
} catch (ValueError $e) {
    echo "init:VE\n";
} catch (TypeError $e) {
    echo "init:TE\n";
}
try {
    hash_file('', '/etc/hosts');
    echo "empty_file:OK\n";
} catch (ValueError $e) {
    echo "empty_file:VE\n";
}
try {
    hash_init('');
    echo "empty_init:OK\n";
} catch (ValueError $e) {
    echo "empty_init:VE\n";
}
restore_error_handler();
echo 'depr=', (int) ($seen >= 2), "\n";
?>
--EXPECT--
file:VE
init:VE
empty_file:VE
empty_init:VE
depr=1
