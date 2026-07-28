--TEST--
mbstring mb_convert_kana() null $string/$mode — DEP+coerce on 8.4 JIT (#24209, peer #24176)
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
$ok = true;
try {
    if ('' !== mb_convert_kana(null)) {
        $ok = false;
    }
    if ('ｱ' !== mb_convert_kana('ｱ', null)) {
        $ok = false;
    }
} catch (TypeError $e) {
    $ok = false;
}
restore_error_handler();
echo $ok && count($seen) >= 2 ? "ok\n" : "bad\n";
?>
--EXPECT--
ok
