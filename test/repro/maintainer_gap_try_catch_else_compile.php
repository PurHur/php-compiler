<?php

declare(strict_types=1);

// #19225 / #31159 — try/catch/else is not php-src syntax; expect Parse error unexpected token "else".
// Run: php bin/vm.php test/repro/maintainer_gap_try_catch_else_compile.php

try {
    echo 'try';
} catch (Throwable $e) {
    echo 'catch';
} else {
    echo 'else';
}
