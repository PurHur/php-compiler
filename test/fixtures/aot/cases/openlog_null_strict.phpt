--TEST--
AOT openlog(null) TypeError under strict_types (#30372, ext/standard/syslog.c)
--FILE--
<?php
declare(strict_types=1);
try {
    var_export(openlog(null, LOG_PID, LOG_USER));
    echo "\nfail\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:openlog(): Argument #1 ($prefix) must be of type string, null given
