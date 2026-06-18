<?php

declare(strict_types=1);

// Maintainer repro for #9722 — final class child after runtime statements must compile-error.

final class C {}

try {
    new C;
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}

class D extends C {}
