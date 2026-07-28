--TEST--
mbstring mb_str_pad() null $string — DEP+coerce on 8.4 (#24176, reverts #19184/#22373)
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
    echo 'pad=', var_export(mb_str_pad(null, 5), true), "\n";
} catch (TypeError $e) {
    echo "TypeError\n";
}
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 1), "\n";
?>
--EXPECT--
pad='     '
depr=1
