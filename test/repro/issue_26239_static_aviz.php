<?php
declare(strict_types=1);

/**
 * Repro #26239 — PHP 8.5 static asymmetric visibility (RFC static-aviz).
 * Run: PHP_COMPILER_PROFILE=8.5 php bin/vm.php test/repro/issue_26239_static_aviz.php
 */
class C {
    public private(set) static string $x = 'a';
    public static function setX(string $v): void { self::$x = $v; }
}
echo C::$x, "\n";
C::setX('b');
echo C::$x, "\n";
try {
    C::$x = 'c';
    echo "direct OK\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
