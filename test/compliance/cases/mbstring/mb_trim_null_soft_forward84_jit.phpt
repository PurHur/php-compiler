--TEST--
mbstring mb_trim/ltrim/rtrim() null $string — DEP+coerce on 8.4 JIT (#24176, reverts #17132)
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
foreach (['mb_trim', 'mb_ltrim', 'mb_rtrim'] as $fn) {
    try {
        if ('' !== $fn(null)) {
            $ok = false;
        }
    } catch (TypeError $e) {
        $ok = false;
    }
}
restore_error_handler();
echo $ok && count($seen) >= 3 ? "ok\n" : "bad\n";
?>
--EXPECT--
ok
