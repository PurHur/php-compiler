<?php

declare(strict_types=1);

// Issue #24746 / #24820 — str_increment()/str_decrement() require PHP_COMPILER_PROFILE>=8.3
// (withheld on default 8.2 reference harness). Run with PROFILE=8.3 or 8.4.
echo str_increment('Az'), "\n";
echo str_increment('z'), "\n";
echo str_increment('A9'), "\n";
echo str_decrement('Ba'), "\n";
echo str_decrement('aa'), "\n";

try {
    str_decrement('a');
    echo "underflow_fail\n";
} catch (\ValueError $e) {
    echo "underflow_ok\n";
}
