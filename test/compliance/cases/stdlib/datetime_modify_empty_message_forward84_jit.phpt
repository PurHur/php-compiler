--TEST--
JIT: DateTime(Immutable)::modify("") DateMalformedStringException cites Empty string (#29301)
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
