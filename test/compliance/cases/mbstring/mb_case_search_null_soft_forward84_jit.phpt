--TEST--
JIT mb_convert_case null — E_DEPRECATED + coerce on 8.4 (#21313)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$seen = 0;
set_error_handler(static function (int $no) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        ++$seen;
    }
    return true;
});
try {
    echo 'mb_convert_case=', var_export(mb_convert_case(null, MB_CASE_UPPER), true), "\n";
} catch (TypeError $e) {
    echo "mb_convert_case: TypeError\n";
}
restore_error_handler();
echo 'depr=', (int) ($seen >= 1), "\n";
?>
--EXPECT--
mb_convert_case=''
depr=1
