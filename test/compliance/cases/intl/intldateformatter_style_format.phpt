--TEST--
IntlDateFormatter style-only create/format + getPattern (#3336)
--FILE--
<?php
$nn = "\u{202F}";
$dt = new DateTime('2020-01-15 12:00:00', new DateTimeZone('UTC'));

$f = IntlDateFormatter::create(
    'en_US',
    IntlDateFormatter::SHORT,
    IntlDateFormatter::SHORT,
    'UTC'
);
echo 'pattern=', $f->getPattern(), "\n";
echo 'format=', $f->format($dt), "\n";
echo 'expect_nnbsp=', (int) (str_contains($f->format($dt), $nn)), "\n";

$f2 = IntlDateFormatter::create('en_US', IntlDateFormatter::SHORT, IntlDateFormatter::NONE, 'UTC');
echo 'short_none=', $f2->format($dt), "\n";

$f3 = IntlDateFormatter::create('en_GB', IntlDateFormatter::SHORT, IntlDateFormatter::SHORT, 'UTC');
echo 'en_GB=', $f3->format($dt), "\n";

$f4 = IntlDateFormatter::create('de_DE', IntlDateFormatter::SHORT, IntlDateFormatter::SHORT, 'UTC');
echo 'de_DE=', $f4->format($dt), "\n";

// Explicit pattern still wins over styles
$f5 = IntlDateFormatter::create(
    'en_US',
    IntlDateFormatter::SHORT,
    IntlDateFormatter::SHORT,
    'UTC',
    IntlDateFormatter::GREGORIAN,
    'yyyy-MM-dd'
);
echo 'explicit=', $f5->format($dt), "\n";
?>
--EXPECT--
pattern=M/d/yy, h:mm a
format=1/15/20, 12:00 PM
expect_nnbsp=1
short_none=1/15/20
en_GB=15/01/2020, 12:00
de_DE=15.01.20, 12:00
explicit=2020-01-15
