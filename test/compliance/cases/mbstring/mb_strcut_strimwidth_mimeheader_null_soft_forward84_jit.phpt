--TEST--
mb_strcut/mb_strimwidth null — E_DEPRECATED + coerce on 8.4 JIT (#21430)
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
    echo 'mb_strcut=', var_export(mb_strcut(null, 0, 1), true), "\n";
} catch (TypeError $e) {
    echo "mb_strcut: TypeError\n";
}
try {
    echo 'mb_strimwidth=', var_export(mb_strimwidth(null, 0, 5, '...'), true), "\n";
} catch (TypeError $e) {
    echo "mb_strimwidth: TypeError\n";
}
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 2), "\n";
echo mb_strcut('abc', 1), "\n";
echo mb_strimwidth('abcdef', 0, 3, '..'), "\n";
?>
--EXPECT--
mb_strcut=''
mb_strimwidth=''
depr=1
bc
a..
