<?php
// Repro for #3336 umbrella — style-only IntlDateFormatter::format (no explicit pattern).
$fmt = IntlDateFormatter::create(
    'en_US',
    IntlDateFormatter::SHORT,
    IntlDateFormatter::SHORT,
    'UTC'
);
echo $fmt->getPattern(), "\n";
echo $fmt->format(new DateTime('2020-01-15 12:00:00', new DateTimeZone('UTC'))), "\n";
