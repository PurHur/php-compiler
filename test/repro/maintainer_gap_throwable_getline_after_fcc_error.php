<?php

declare(strict_types=1);

// #24397 — FCC non-static Error must expose getLine()/getFile() like Zend.

class C
{
    public function m(): void
    {
    }
}

try {
    $fn = C::m(...);
    echo "fail: expected Error\n";
    exit(1);
} catch (Error $e) {
    $msg = $e->getMessage();
    if (!str_contains($msg, 'Non-static method') || !str_contains($msg, 'cannot be called statically')) {
        echo "fail message: {$msg}\n";
        exit(1);
    }
    $line = $e->getLine();
    // Zend: FCC expression line (15 in this file). Require exact match under VM.
    if (15 !== $line) {
        echo "fail getLine: {$line}\n";
        exit(1);
    }
    $file = $e->getFile();
    if ('' === $file) {
        echo "fail getFile empty\n";
        exit(1);
    }
    echo "line={$line}\n";
}

try {
    throw new Error('x');
} catch (Error $e) {
    if ($e->getLine() < 1) {
        echo "fail plain Error getLine\n";
        exit(1);
    }
}

echo "survived\n";
