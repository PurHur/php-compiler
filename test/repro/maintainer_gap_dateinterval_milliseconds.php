<?php

declare(strict_types=1);

/**
 * Repro #26694 — DateInterval::createFromDateString('N milliseconds') sets $f.
 * php-src: ext/date/php_date.c / timelib relative units
 */
$i = DateInterval::createFromDateString('500 milliseconds');
if (false === $i) {
    echo "false\n";
    exit(0);
}
echo 's=', $i->s, ' f=', $i->f, PHP_EOL;

$j = date_interval_create_from_date_string('250 milliseconds');
if (false === $j) {
    echo "fn_false\n";
    exit(0);
}
echo 'fn s=', $j->s, ' f=', $j->f, PHP_EOL;

$k = DateInterval::createFromDateString('1 second 500 milliseconds');
echo 'combo s=', $k->s, ' f=', $k->f, PHP_EOL;

$u = DateInterval::createFromDateString('500 microseconds');
echo 'us s=', $u->s, ' f=', $u->f, PHP_EOL;

$bad = @DateInterval::createFromDateString('500 widgets');
echo 'bad=', false === $bad ? 'false' : 'ok', PHP_EOL;
