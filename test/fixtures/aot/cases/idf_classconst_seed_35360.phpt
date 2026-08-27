--TEST--
IntlDateFormatter::SHORT ClassConstFetch seeds for AOT create (#35360)
--FILE--
<?php
echo 'SHORT=', IntlDateFormatter::SHORT, "\n";
echo 'NONE=', IntlDateFormatter::NONE, "\n";
echo 'GREGORIAN=', IntlDateFormatter::GREGORIAN, "\n";
$f = IntlDateFormatter::create(
    'en_US',
    IntlDateFormatter::SHORT,
    IntlDateFormatter::NONE,
    'UTC',
    IntlDateFormatter::GREGORIAN
);
echo $f ? 'ok' : 'bad', "\n";
--EXPECT--
SHORT=3
NONE=-1
GREGORIAN=1
ok
