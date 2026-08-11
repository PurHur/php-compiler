--TEST--
Language: missing include/require emit Zend two-step diagnostics (#30029, fopen_wrappers.c)
--FILE--
<?php
set_include_path('.');
set_error_handler(static function (int $errno, string $message): bool {
    echo 'W:', $message, "\n";

    return true;
});

$path = '/tmp/no_such_phpc_include_30029.php';

$r = include $path;
echo 'include=', var_export($r, true), "\n";

$r2 = include_once $path;
echo 'include_once=', var_export($r2, true), "\n";

try {
    require $path;
} catch (Error $e) {
    echo 'require=', $e->getMessage(), "\n";
}

try {
    require_once $path;
} catch (Error $e) {
    echo 'require_once=', $e->getMessage(), "\n";
}
--EXPECT--
W:include(/tmp/no_such_phpc_include_30029.php): Failed to open stream: No such file or directory
W:include(): Failed opening '/tmp/no_such_phpc_include_30029.php' for inclusion (include_path='.')
include=false
W:include_once(/tmp/no_such_phpc_include_30029.php): Failed to open stream: No such file or directory
W:include_once(): Failed opening '/tmp/no_such_phpc_include_30029.php' for inclusion (include_path='.')
include_once=false
W:require(/tmp/no_such_phpc_include_30029.php): Failed to open stream: No such file or directory
require=Failed opening required '/tmp/no_such_phpc_include_30029.php' (include_path='.')
W:require_once(/tmp/no_such_phpc_include_30029.php): Failed to open stream: No such file or directory
require_once=Failed opening required '/tmp/no_such_phpc_include_30029.php' (include_path='.')
