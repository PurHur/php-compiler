--TEST--
stdlib date_create(null) / DateTime(null) — null datetime coerces on 8.4 profile (#18903, ext/date/php_date.c)
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
    $result = $factory();
    echo $label.': '.(false === $result ? 'false' : get_class($result))."\n";
}

foreach (['date_create' => static fn () => date_create(''), 'DateTime' => static fn () => new DateTime('')] as $label => $factory) {
    $result = $factory();
    echo $label."(''): ", $result instanceof DateTime ? "ok\n" : "bad\n";
}
--EXPECT--
date_create: DateTime
date_create_immutable: DateTimeImmutable
DateTime: DateTime
DateTimeImmutable: DateTimeImmutable
date_create(''): ok
DateTime(''): ok
