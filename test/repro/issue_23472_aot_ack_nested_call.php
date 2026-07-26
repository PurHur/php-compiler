<?php

declare(strict_types=1);

/**
 * Issue #23472: AOT nested recursive call `f($a-1, f(...))` must not wire both ARG_SENDs
 * to the nested call result (produced Ack(r,r) → segfault). Zend/VM already correct.
 *
 * Run: php bin/vm.php test/repro/issue_23472_aot_ack_nested_call.php
 * AOT: ./phpc build -o /tmp/ack23472 test/repro/issue_23472_aot_ack_nested_call.php && /tmp/ack23472
 */

function Ack(int $m, int $n): int
{
    if ($m == 0) {
        return $n + 1;
    }
    if ($n == 0) {
        return Ack($m - 1, 1);
    }

    return Ack($m - 1, Ack($m, ($n - 1)));
}

echo Ack(3, 4);
echo "\n";
