<?php

declare(strict_types=1);

/**
 * Repro for #26735 — ssh2_poll registration, constants, type guards.
 * Run: PHP_COMPILER_ENABLE_SSH2=1 php bin/vm.php test/repro/issue_26735_ssh2_poll.php
 */
echo 'poll=', function_exists('ssh2_poll') ? '1' : '0', "\n";
echo 'POLLIN=', defined('SSH2_POLLIN') ? (string) SSH2_POLLIN : 'missing', "\n";
echo 'POLLOUT=', defined('SSH2_POLLOUT') ? (string) SSH2_POLLOUT : 'missing', "\n";
try {
    ssh2_poll(null);
    echo "type=fail\n";
} catch (TypeError $e) {
    echo str_contains($e->getMessage(), 'array') ? "type=ok\n" : "type=msg\n";
}
echo 'empty=', (string) @ssh2_poll([]), "\n";
