--TEST--
mbstring mb_ucfirst()/mb_lcfirst() null $string — DEP+coerce on 8.4 JIT (#24176, reverts #19433)
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
foreach (['mb_ucfirst', 'mb_lcfirst'] as $f) {
    try {
        if ('' !== $f(null)) {
            $ok = false;
        }
    } catch (TypeError $e) {
        $ok = false;
    }
}
restore_error_handler();
if ('Über' !== mb_ucfirst('über')) {
    $ok = false;
}
echo $ok && count($seen) >= 2 ? "ok\n" : "bad\n";
?>
--EXPECT--
ok
