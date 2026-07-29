<?php

declare(strict_types=1);

// Issue #24746 — str_increment()/str_decrement() on default 8.4.0-dev profile.
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
