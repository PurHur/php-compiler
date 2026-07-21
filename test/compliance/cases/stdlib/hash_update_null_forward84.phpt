--TEST--
stdlib hash_update() null $data DEP+coerce on 8.4 (#21557, reverts #20195)
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
$c = hash_init('md5');
try {
    hash_update($c, null);
    echo hash_final($c), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
restore_error_handler();
echo 'depr=', (int) ($seen >= 1), "\n";
$c2 = hash_init('md5');
hash_update($c2, '');
echo hash_final($c2), "\n";
?>
--EXPECT--
d41d8cd98f00b204e9800998ecf8427e
depr=1
d41d8cd98f00b204e9800998ecf8427e
