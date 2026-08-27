<?php
// #35360 — ClassConstFetch must seed IntlDateFormatter::* for thin AOT (peer #35358).
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
