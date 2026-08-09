--TEST--
DateTime(Immutable)::createFromFormat(null) deprecation cites parameter #2 ($datetime) (#29269, ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(function (int $errno, string $errstr): bool {
    echo "ERR:$errno:$errstr\n";
    return true;
});
foreach ([
    ['DateTime', static fn () => DateTime::createFromFormat('Y', null)],
    ['DateTimeImmutable', static fn () => DateTimeImmutable::createFromFormat('Y-m-d', null)],
] as [$label, $fn]) {
    try {
        $r = $fn();
        echo $label, ':ret=', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $label, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
--EXPECT--
ERR:8192:DateTime::createFromFormat(): Passing null to parameter #2 ($datetime) of type string is deprecated
DateTime:ret=false
ERR:8192:DateTimeImmutable::createFromFormat(): Passing null to parameter #2 ($datetime) of type string is deprecated
DateTimeImmutable:ret=false
