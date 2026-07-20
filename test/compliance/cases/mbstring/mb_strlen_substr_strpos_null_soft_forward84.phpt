--TEST--
mb_strlen/mb_substr/mb_strpos null — E_DEPRECATED + coerce on 8.4 (#21197, reverts #19297 for these)
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
    echo 'mb_strlen=', var_export(mb_strlen(null), true), "\n";
} catch (TypeError $e) {
    echo "mb_strlen: TypeError\n";
}
try {
    echo 'mb_substr=', var_export(mb_substr(null, 0), true), "\n";
} catch (TypeError $e) {
    echo "mb_substr: TypeError\n";
}
try {
    echo 'mb_strpos=', var_export(mb_strpos(null, 'a'), true), "\n";
} catch (TypeError $e) {
    echo "mb_strpos: TypeError\n";
}
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 3), "\n";
?>
--EXPECT--
mb_strlen=0
mb_substr=''
mb_strpos=false
depr=1
