--TEST--
date/gmdate/date_create null TypeError on 8.4 forward profile (#18889 #18890, ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    ['date', static fn () => date(null)],
    ['gmdate', static fn () => gmdate(null)],
    ['date_create', static fn () => date_create(null)],
    ['date_create_immutable', static fn () => date_create_immutable(null)],
] as [$label, $call]) {
    try {
        $call();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo "$label: ".$e->getMessage()."\n";
    }
}
--EXPECT--
date: date(): Argument #1 ($format) must be of type string, null given
gmdate: gmdate(): Argument #1 ($format) must be of type string, null given
date_create: date_create(): Argument #1 ($datetime) must be of type string, null given
date_create_immutable: date_create_immutable(): Argument #1 ($datetime) must be of type string, null given
