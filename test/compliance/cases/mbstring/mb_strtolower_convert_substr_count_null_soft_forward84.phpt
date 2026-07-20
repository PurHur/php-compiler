--TEST--
mb_strtolower/mb_convert_encoding/mb_substr_count null — E_DEPRECATED + coerce on 8.4 (#21282)
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
    echo 'mb_strtolower=', var_export(mb_strtolower(null), true), "\n";
} catch (TypeError $e) {
    echo "mb_strtolower: TypeError\n";
}
try {
    echo 'mb_convert_encoding=', var_export(mb_convert_encoding(null, 'UTF-8', 'UTF-8'), true), "\n";
} catch (TypeError $e) {
    echo "mb_convert_encoding: TypeError\n";
}
try {
    echo 'mb_substr_count=', var_export(mb_substr_count(null, 'a'), true), "\n";
} catch (TypeError $e) {
    echo "mb_substr_count: TypeError\n";
}
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 3), "\n";
?>
--EXPECT--
mb_strtolower=''
mb_convert_encoding=''
mb_substr_count=0
depr=1
