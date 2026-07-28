--TEST--
JIT mb_strwidth(null) — E_DEPRECATED + coerce on 8.4 (#24257, ext/mbstring/mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
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
    echo 'mb_strwidth=', var_export(mb_strwidth(null), true), "\n";
} catch (TypeError $e) {
    echo "mb_strwidth: TypeError\n";
}
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 1), "\n";
?>
--EXPECT--
mb_strwidth=0
depr=1
