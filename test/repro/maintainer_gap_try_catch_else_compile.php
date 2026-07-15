<?php

declare(strict_types=1);

// #19225 — try/catch/else parse + run (Zend/zend_compile.c, PHP 8.4 forward profile).
// Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_try_catch_else_compile.php

try {
    echo 'try';
} catch (Throwable $e) {
    echo 'catch';
} else {
    echo 'else';
}
