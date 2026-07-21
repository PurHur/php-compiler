--TEST--
stdlib hash_hmac() null $key DEP+coerce on 8.4 (#21557, reverts #20175)
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
    $r = hash_hmac('md5', 'd', null);
    echo var_export($r, true), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
restore_error_handler();
echo 'depr=', (int) ($seen >= 1), "\n";
echo var_export(hash_hmac('md5', 'd', ''), true), "\n";
?>
--EXPECT--
'5f877893cf18d622daed614c1df6f2f9'
depr=1
'5f877893cf18d622daed614c1df6f2f9'
