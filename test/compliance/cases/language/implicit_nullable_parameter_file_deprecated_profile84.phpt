--TEST--
language: file-level implicit nullable typed params emit E_DEPRECATED on PROFILE=8.4 (#22987, #29274)
--ENV--
PHP_COMPILER_PROFILE=8.4
--INI--
error_reporting=E_ALL
--FILE--
<?php
function issue22987_84_fn(int $x = null): void {}
class Issue22987_84_Class {
    public function method(int $y = null): void {}
}
$c = function (int $z = null): int { return 1; };
$a = fn (int $w = null): int => 1;
echo "ok\n";
--EXPECTF--
PHP Deprecated:  issue22987_84_fn(): Implicitly marking parameter $x as nullable is deprecated, the explicit nullable type must be used instead in %s on line %d
PHP Deprecated:  Issue22987_84_Class::method(): Implicitly marking parameter $y as nullable is deprecated, the explicit nullable type must be used instead in %s on line %d
PHP Deprecated:  {closure:%s:%d}(): Implicitly marking parameter $z as nullable is deprecated, the explicit nullable type must be used instead in %s on line %d
PHP Deprecated:  {closure:%s:%d}(): Implicitly marking parameter $w as nullable is deprecated, the explicit nullable type must be used instead in %s on line %d
ok
