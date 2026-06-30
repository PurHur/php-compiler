<?php

declare(strict_types=1);

$a = [1, 2];
try {
    array_reduce($a, static function (): void {
        throw new Exception('boom');
    });
} catch (Exception $e) {
    // Zend: caught here, script continues
}
echo "ok\n";
