--TEST--
Language: method-scoped closure/arrow TypeError uses Class::{closure} (#29953, zend_execute.c)
--FILE--
<?php
class C29953 {
    public function m() {
        $f = function (int $x) {};
        try {
            $f(null);
        } catch (Throwable $e) {
            echo $e->getMessage(), "\n";
        }
        $a = fn (int $x) => $x;
        try {
            $a(null);
        } catch (Throwable $e) {
            echo $e->getMessage(), "\n";
        }
    }
    public static function s() {
        $f = function (self $x) {};
        try {
            $f(null);
        } catch (Throwable $e) {
            echo $e->getMessage(), "\n";
        }
    }
}
(new C29953)->m();
C29953::s();
$free = function (int $x) {};
try {
    $free(null);
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECTF--
C29953::{closure}(): Argument #1 ($x) must be of type int, null given, called in %s on line %d
C29953::{closure}(): Argument #1 ($x) must be of type int, null given, called in %s on line %d
C29953::{closure}(): Argument #1 ($x) must be of type C29953, null given, called in %s on line %d
{closure}(): Argument #1 ($x) must be of type int, null given, called in %s on line %d
