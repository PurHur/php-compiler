--TEST--
JIT iconv()/iconv_strlen(+substr/strpos) null string — E_DEPRECATED + coerce on 8.4 (#21197)
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
    echo 'iconv=', var_export(iconv('UTF-8', 'UTF-8', null), true), "\n";
} catch (TypeError $e) {
    echo "iconv: TypeError\n";
}
try {
    echo 'iconv_strlen=', var_export(iconv_strlen(null), true), "\n";
} catch (TypeError $e) {
    echo "iconv_strlen: TypeError\n";
}
try {
    echo 'iconv_substr=', var_export(iconv_substr(null, 0, 1), true), "\n";
} catch (TypeError $e) {
    echo "iconv_substr: TypeError\n";
}
try {
    echo 'iconv_strpos=', var_export(iconv_strpos(null, 'a'), true), "\n";
} catch (TypeError $e) {
    echo "iconv_strpos: TypeError\n";
}
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 4), "\n";
?>
--EXPECT--
iconv=''
iconv_strlen=0
iconv_substr=''
iconv_strpos=false
depr=1
