<?php

declare(strict_types=1);

$checks = ['function_exists', 'class_exists', 'extension_loaded', 'ini_get', 'get_cfg_var'];
foreach ($checks as $fn) {
    try {
        $fn(null);
        echo "fail: {$fn}(null)\n";
        exit(1);
    } catch (TypeError $e) {
        // TypeError only — avoid $e->getMessage() in JIT loops (ExceptionBridge unwind).
    }
}
try {
    ini_set(null, '1');
    echo "fail: ini_set(null)\n";
    exit(1);
} catch (TypeError $e) {
}
echo "ok\n";
