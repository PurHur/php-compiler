<?php
/**
 * Repro #26402 — untyped __toString returning int under strict_types must TypeError (Zend).
 */
declare(strict_types=1);

class C
{
    public function __toString()
    {
        return 123;
    }
}

try {
    echo (new C), PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
