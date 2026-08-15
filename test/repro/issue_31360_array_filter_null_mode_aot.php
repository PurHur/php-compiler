<?php

declare(strict_types=1);

// #31360 — AOT-safe repro (soft filterDefault AOT segfaults on master; TypeError only).
try {
    array_filter([0, 1, 2], null, null);
    echo "fail null mode\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
