<?php
/**
 * Repro #27954 — DateInterval::createFromDateString accepts next/last day, yesterday, tomorrow.
 * php-src: ext/date/php_date.c / ext/date/lib/parse_date.re
 */
foreach (['next day', 'last day', 'yesterday', 'tomorrow', '1 day', 'previous day', 'this day'] as $s) {
    $i = @DateInterval::createFromDateString($s);
    if ($i === false) {
        echo $s, " => false\n";
    } else {
        echo $s, ' => d=', $i->d, ' invert=', $i->invert, "\n";
    }
}
$fn = @date_interval_create_from_date_string('tomorrow');
echo 'fn tomorrow => d=', $fn->d, ' invert=', $fn->invert, "\n";
