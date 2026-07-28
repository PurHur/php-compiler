--TEST--
mbstring mb_convert_kana() null $string/$mode — DEP+coerce on 8.4 (#24209, peer #24176)
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
    echo 'string=', var_export(mb_convert_kana(null), true), "\n";
    echo 'mode=', var_export(mb_convert_kana('ｱ', null), true), "\n";
} catch (TypeError $e) {
    echo "TypeError\n";
}
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 2), "\n";
?>
--EXPECT--
string=''
mode='ｱ'
depr=1
