--TEST--
date/gmdate/date_create null TypeError on 8.4 forward profile (#18889, ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    ['date', static fn () => date(null)],
    ['gmdate', static fn () => gmdate(null)],
    ['date_create', static fn () => date_create(null)],
    ['date_create_immutable', static fn () => date_create_immutable(null)],
] as [$label, $factory]) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': TypeError'."\n";
    }
}
--EXPECT--
date: TypeError
gmdate: TypeError
date_create: TypeError
date_create_immutable: TypeError
