<?php
/** Maintainer repro for #12164 — forward_static_call() rejects global scope even for plain functions. */
declare(strict_types=1);

try {
    forward_static_call('strlen', 'abc');
    echo "unexpected_ok\n";
    exit(1);
} catch (Error $e) {
    echo $e->getMessage(), "\n";
    exit(0);
}
