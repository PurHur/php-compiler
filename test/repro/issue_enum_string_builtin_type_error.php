<?php
declare(strict_types=1);

/**
 * Issue #5524 — string builtins must TypeError on enum case operands (php-src-strict).
 */

enum E: string {
    case A = 'x';
}

$failed = 0;
$checks = [
    'explode' => static function (): void {
        explode('.', E::A);
    },
    'strpos' => static function (): void {
        strpos(E::A, 'x');
    },
    'preg_match' => static function (): void {
        preg_match('/x/', E::A);
    },
    'strlen' => static function (): void {
        strlen(E::A);
    },
    'substr' => static function (): void {
        substr(E::A, 0);
    },
    'htmlspecialchars' => static function (): void {
        htmlspecialchars(E::A);
    },
];

foreach ($checks as $fn => $call) {
    try {
        $call();
        echo "{$fn}: uncaught\n";
        ++$failed;
    } catch (TypeError $e) {
        echo "{$fn}: {$e->getMessage()}\n";
    } catch (Throwable $e) {
        echo "{$fn}: ".get_class($e).": {$e->getMessage()}\n";
        ++$failed;
    }
}

exit($failed > 0 ? 1 : 0);
