--TEST--
Language: $obj->x++ emits E_DEPRECATED + Undefined property E_WARNING (#29241, zend_object_handlers.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
ini_set('error_reporting', '32767');

class U {}
$errs = [];
set_error_handler(static function (int $errno, string $errstr) use (&$errs): bool {
    $errs[] = [$errno, $errstr];
    return true;
});
$u = new U();
$u->x++;
restore_error_handler();
foreach ($errs as $e) {
    echo "err[{$e[0]}] {$e[1]}\n";
}
echo "x=", $u->x, "\n";

#[\AllowDynamicProperties]
class D {}
$errs = [];
set_error_handler(static function (int $errno, string $errstr) use (&$errs): bool {
    $errs[] = [$errno, $errstr];
    return true;
});
$d = new D();
$d->y++;
restore_error_handler();
foreach ($errs as $e) {
    echo "err[{$e[0]}] {$e[1]}\n";
}
echo "y=", $d->y, "\n";

$errs = [];
set_error_handler(static function (int $errno, string $errstr) use (&$errs): bool {
    $errs[] = [$errno, $errstr];
    return true;
});
$s = new stdClass();
$s->z--;
restore_error_handler();
foreach ($errs as $e) {
    echo "err[{$e[0]}] {$e[1]}\n";
}
echo "z=", var_export($s->z, true), "\n";
--EXPECTF--
err[8192] Creation of dynamic property U::$x is deprecated
err[2] Undefined property: U::$x
x=1
err[2] Undefined property: D::$y
y=1
err[2] Undefined property: stdClass::$z
err[2] Decrement on type null has no effect, this will change in the next major version of PHP
z=NULL
