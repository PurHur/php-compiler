<?php
// Compile-only (#12164): forward_static_call() global scope Error lowering.
declare(strict_types=1);

try {
    forward_static_call('strlen', 'abc');
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
