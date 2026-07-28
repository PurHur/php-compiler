--TEST--
mbstring mb_trim/ltrim/rtrim() null $string — DEP+coerce on 8.4 (#24176, reverts #17132)
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
foreach (['mb_trim', 'mb_ltrim', 'mb_rtrim'] as $fn) {
    try {
        echo $fn, '=', var_export($fn(null), true), "\n";
    } catch (TypeError $e) {
        echo $fn, ": TypeError\n";
    }
}
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 3), "\n";
?>
--EXPECT--
mb_trim=''
mb_ltrim=''
mb_rtrim=''
depr=1
