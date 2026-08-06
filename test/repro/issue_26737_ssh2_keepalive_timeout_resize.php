<?php

declare(strict_types=1);

/**
 * Repro for #26737 — ssh2 keepalive / set_timeout / shell_resize registration + guards.
 * Run: PHP_COMPILER_ENABLE_SSH2=1 php bin/vm.php test/repro/issue_26737_ssh2_keepalive_timeout_resize.php
 */
foreach (['ssh2_keepalive_config', 'ssh2_keepalive_send', 'ssh2_set_timeout', 'ssh2_shell_resize'] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}
try {
    ssh2_keepalive_config(null, true, 10);
    echo "ka_cfg_type=fail\n";
} catch (TypeError $e) {
    echo (str_contains($e->getMessage(), 'SSH2\\Session') || str_contains($e->getMessage(), 'SSH2\Session'))
        ? "ka_cfg_type=ok\n"
        : "ka_cfg_type=msg\n";
}
try {
    ssh2_shell_resize(null, 80, 24);
    echo "resize_type=fail\n";
} catch (TypeError $e) {
    echo (str_contains($e->getMessage(), 'SSH2\\Stream') || str_contains($e->getMessage(), 'SSH2\Stream'))
        ? "resize_type=ok\n"
        : "resize_type=msg\n";
}
