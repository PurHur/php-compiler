--TEST--
mb_strtoupper/mb_convert_case/mb_str* search null — E_DEPRECATED + coerce on 8.4 (#21313)
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
    echo 'mb_strtoupper=', var_export(mb_strtoupper(null), true), "\n";
} catch (TypeError $e) {
    echo "mb_strtoupper: TypeError\n";
}
try {
    echo 'mb_convert_case=', var_export(mb_convert_case(null, MB_CASE_UPPER), true), "\n";
} catch (TypeError $e) {
    echo "mb_convert_case: TypeError\n";
}
try {
    echo 'mb_strstr=', var_export(mb_strstr(null, 'a'), true), "\n";
} catch (TypeError $e) {
    echo "mb_strstr: TypeError\n";
}
try {
    echo 'mb_stripos=', var_export(mb_stripos(null, 'a'), true), "\n";
} catch (TypeError $e) {
    echo "mb_stripos: TypeError\n";
}
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 4), "\n";
?>
--EXPECT--
mb_strtoupper=''
mb_convert_case=''
mb_strstr=false
mb_stripos=false
depr=1
