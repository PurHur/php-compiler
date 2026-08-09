--TEST--
JIT: date_modify(null) deprecation cites parameter #2 + Empty string warning (#29302)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(function (int $errno, string $errstr): bool {
    echo "ERR:$errno:$errstr\n";
    return true;
});
$dt = date_create('2020-01-01');
try {
    $r = date_modify($dt, null);
    echo 'ret=', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
ERR:8192:date_modify(): Passing null to parameter #2 ($modifier) of type string is deprecated
ERR:2:date_modify(): Failed to parse time string () at position 0 ( ): Empty string
ret=false
