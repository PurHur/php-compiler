--TEST--
DateTime(Immutable)::modify("") DateMalformedStringException cites Empty string (#29301, ext/date/php_date.c)
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
    ['DateTime', static fn () => (new DateTime('2020-01-01'))->modify('')],
    ['DateTimeImmutable', static fn () => (new DateTimeImmutable('2020-01-01'))->modify('')],
    ['DateTime-null', static fn () => (new DateTime('2020-01-01'))->modify(null)],
    ['DateTimeImmutable-null', static fn () => (new DateTimeImmutable('2020-01-01'))->modify(null)],
] as [$label, $fn]) {
    try {
        $fn();
        echo "$label: no throw\n";
    } catch (Throwable $e) {
        echo $label, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
--EXPECT--
DateTime:DateMalformedStringException:DateTime::modify(): Failed to parse time string () at position 0 ( ): Empty string
DateTimeImmutable:DateMalformedStringException:DateTimeImmutable::modify(): Failed to parse time string () at position 0 ( ): Empty string
ERR:8192:DateTime::modify(): Passing null to parameter #1 ($modifier) of type string is deprecated
DateTime-null:DateMalformedStringException:DateTime::modify(): Failed to parse time string () at position 0 ( ): Empty string
ERR:8192:DateTimeImmutable::modify(): Passing null to parameter #1 ($modifier) of type string is deprecated
DateTimeImmutable-null:DateMalformedStringException:DateTimeImmutable::modify(): Failed to parse time string () at position 0 ( ): Empty string
