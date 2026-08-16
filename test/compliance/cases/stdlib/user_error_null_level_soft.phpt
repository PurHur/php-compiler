--TEST--
user_error/trigger_error null $error_level soft DEP + ValueError (#31464)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});
try {
    user_error('x', null);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    trigger_error('x', null);
    echo "trigger ok\n";
} catch (Throwable $e) {
    echo 'trigger ', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
ERR[8192]: user_error(): Passing null to parameter #2 ($error_level) of type int is deprecated
ValueError: user_error(): Argument #2 ($error_level) must be one of E_USER_ERROR, E_USER_WARNING, E_USER_NOTICE, or E_USER_DEPRECATED
ERR[8192]: trigger_error(): Passing null to parameter #2 ($error_level) of type int is deprecated
trigger ValueError: trigger_error(): Argument #2 ($error_level) must be one of E_USER_ERROR, E_USER_WARNING, E_USER_NOTICE, or E_USER_DEPRECATED
