--TEST--
stdlib ssh2 keepalive/timeout/shell_resize registration + type guards (#26737)
--ENV--
PHP_COMPILER_ENABLE_SSH2=1
--FILE--
<?php
declare(strict_types=1);
$need = [
    'ssh2_keepalive_config',
    'ssh2_keepalive_send',
    'ssh2_set_timeout',
    'ssh2_shell_resize',
];
foreach ($need as $f) {
    if (!function_exists($f)) {
        echo "skip\n";
        exit(0);
    }
}
foreach ($need as $f) {
    echo function_exists($f) ? '1' : '0';
}
echo "\n";
try {
    ssh2_keepalive_config(null, true, 10);
    echo "ka_cfg_type_fail\n";
} catch (TypeError $e) {
    echo (str_contains($e->getMessage(), 'SSH2\\Session') || str_contains($e->getMessage(), 'SSH2\Session')) ? "ka_cfg_type_ok\n" : "ka_cfg_type_msg\n";
}
try {
    ssh2_keepalive_send(null);
    echo "ka_send_type_fail\n";
} catch (TypeError $e) {
    echo (str_contains($e->getMessage(), 'SSH2\\Session') || str_contains($e->getMessage(), 'SSH2\Session')) ? "ka_send_type_ok\n" : "ka_send_type_msg\n";
}
try {
    ssh2_set_timeout(null, 1);
    echo "timeout_type_fail\n";
} catch (TypeError $e) {
    echo (str_contains($e->getMessage(), 'SSH2\\Session') || str_contains($e->getMessage(), 'SSH2\Session')) ? "timeout_type_ok\n" : "timeout_type_msg\n";
}
try {
    ssh2_shell_resize(null, 80, 24);
    echo "resize_type_fail\n";
} catch (TypeError $e) {
    echo (str_contains($e->getMessage(), 'SSH2\\Stream') || str_contains($e->getMessage(), 'SSH2\Stream')) ? "resize_type_ok\n" : "resize_type_msg\n";
}
try {
    ssh2_keepalive_config();
    echo "ka_cfg_argc_fail\n";
} catch (ArgumentCountError $e) {
    echo "ka_cfg_argc_ok\n";
}
?>
--EXPECT--
1111
ka_cfg_type_ok
ka_send_type_ok
timeout_type_ok
resize_type_ok
ka_cfg_argc_ok
