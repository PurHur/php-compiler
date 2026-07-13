--TEST--
stdlib date_create(null) / DateTime(null) — TypeError on 8.4 profile (#18730, ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    ['date_create', static fn () => date_create(null)],
    ['date_create_immutable', static fn () => date_create_immutable(null)],
    ['DateTime', static fn () => new DateTime(null)],
    ['DateTimeImmutable', static fn () => new DateTimeImmutable(null)],
] as [$label, $factory]) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}

foreach (['date_create' => static fn () => date_create(''), 'DateTime' => static fn () => new DateTime('')] as $label => $factory) {
    $result = $factory();
    echo $label."(''): ", $result instanceof DateTime ? "ok\n" : "bad\n";
}
--EXPECT--
date_create: date_create(): Argument #1 ($datetime) must be of type string, null given
date_create_immutable: date_create_immutable(): Argument #1 ($datetime) must be of type string, null given
DateTime: DateTime::__construct(): Argument #1 ($datetime) must be of type string, null given
DateTimeImmutable: DateTimeImmutable::__construct(): Argument #1 ($datetime) must be of type string, null given
date_create(''): ok
DateTime(''): ok
