<?php

declare(strict_types=1);

/**
 * #27749 AOT probe — 4th positional substr arg → ArgumentCountError; 3-arg unchanged.
 *
 * Run: PHP_COMPILER_PROFILE=8.4 ./phpc build -o /tmp/x test/repro/issue_27749_substr_truncate_phantom_aot.php && /tmp/x
 *
 * Note: get_class() on catchable ArgumentCountError is empty under thin AOT (peer str_rot13 #28313);
 * message + catch type are the gate.
 */

try {
    echo substr('abcdef', 0, 3, true), "\n";
    echo "FAIL: 4-arg accepted\n";
    exit(1);
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}

echo substr('abcdef', 0, 3), "\n";
