<?php
/**
 * Repro #26426 — `__invoke(): string` returning int under strict_types must TypeError (Zend).
 * Issue-shaped: try/catch around `(new C)()` (class decl is outside the try CFG block).
 */
declare(strict_types=1);

class C
{
    public function __invoke(): string
    {
        return 5;
    }
}

try {
    echo (new C)(), PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
