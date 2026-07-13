--TEST--
stdlib date_create(null) / DateTime(null) — TypeError JIT on 8.4 profile (#18730, ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$ok = true;
try {
    date_create(null);
} catch (TypeError $e) {
    $ok = ('date_create(): Argument #1 ($datetime) must be of type string, null given' === $e->getMessage());
}
echo 'date_create: ', $ok ? 'TypeError' : 'fail', "\n";

$ok = true;
try {
    new DateTime(null);
} catch (TypeError $e) {
    $ok = ('DateTime::__construct(): Argument #1 ($datetime) must be of type string, null given' === $e->getMessage());
}
echo 'DateTime: ', $ok ? 'TypeError' : 'fail', "\n";
--EXPECT--
date_create: TypeError
DateTime: TypeError
