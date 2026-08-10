<?php
/** Repro #29953 — method-scoped closure TypeError uses Class::{closure}. */
class C {
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
(new C)->m();
C::s();
$free = function (int $x) {};
try {
    $free(null);
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
