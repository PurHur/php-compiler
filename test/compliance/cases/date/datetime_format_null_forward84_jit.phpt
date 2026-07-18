--TEST--
JIT DateTime::format()/date_format(null) TypeError on 8.4 forward profile (#20693)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
foreach ([
    'DateTime::format' => static fn () => (new DateTime('2020-01-01'))->format(null),
    'DateTimeImmutable::format' => static fn () => (new DateTimeImmutable('2020-01-01'))->format(null),
    'date_format' => static fn () => date_format(date_create('2020-01-01'), null),
] as $name => $call) {
    try {
        $call();
        echo "{$name}: COERCE\n";
    } catch (TypeError $e) {
        echo "{$name}: TypeError\n";
    }
}
?>
--EXPECT--
DateTime::format: TypeError
DateTimeImmutable::format: TypeError
date_format: TypeError
