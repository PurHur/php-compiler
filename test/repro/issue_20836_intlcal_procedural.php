<?php

declare(strict_types=1);

$c = IntlCalendar::createInstance('UTC', 'en_US');
echo 'oop_type=' . $c->getType() . "\n";
echo 'oop_year=' . $c->get(IntlCalendar::FIELD_YEAR) . "\n";
foreach (['intlcal_create_instance', 'intlcal_get', 'intlcal_get_type', 'intlcal_add'] as $f) {
    echo $f . '=' . (function_exists($f) ? 'yes' : 'no') . "\n";
}
$p = intlcal_create_instance('UTC', 'en_US');
intlcal_set_time($p, 1705320000000.0);
echo 'proc_type=' . intlcal_get_type($p) . "\n";
echo 'proc_year=' . intlcal_get($p, IntlCalendar::FIELD_YEAR) . "\n";
intlcal_add($p, IntlCalendar::FIELD_DAY_OF_MONTH, 1);
echo 'proc_dom=' . intlcal_get($p, IntlCalendar::FIELD_DAY_OF_MONTH) . "\n";
