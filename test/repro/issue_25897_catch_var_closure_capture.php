<?php

/**
 * #25897 — catch-bound Exception must survive use()/arrow capture (php-src-strict).
 *
 * Locals before the catch force the catch CV off slot 0 so a wrong outer-slot rewrite
 * cannot look green by coincidence.
 */
$a = 1;
$b = 2;
$c = 3;
$d = 4;
try {
    throw new Exception('e2');
} catch (Exception $e) {
    echo 'direct=', $e->getMessage(), "\n";
    $fn = function () use ($e) {
        return $e === null ? 'NULL' : $e->getMessage();
    };
    echo 'closure=', $fn(), "\n";
    echo 'arrow=', (fn () => $e === null ? 'NULL' : $e->getMessage())(), "\n";
}
