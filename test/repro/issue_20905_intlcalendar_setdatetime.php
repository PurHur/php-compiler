<?php
/** Repro #20905 — IntlCalendar::setDateTime (php-src calendar.stub.php). */
$c = IntlCalendar::createInstance('UTC', 'en_US');
echo 'setDate=', method_exists($c, 'setDate') ? 'yes' : 'no', "\n";
echo 'setDateTime=', method_exists($c, 'setDateTime') ? 'yes' : 'no', "\n";
$c->setDateTime(2024, 5, 15, 14, 30, 45);
echo 'H=', $c->get(IntlCalendar::FIELD_HOUR_OF_DAY),
    ' i=', $c->get(IntlCalendar::FIELD_MINUTE),
    ' s=', $c->get(IntlCalendar::FIELD_SECOND), "\n";
echo 'Y=', $c->get(IntlCalendar::FIELD_YEAR),
    ' M=', $c->get(IntlCalendar::FIELD_MONTH),
    ' D=', $c->get(IntlCalendar::FIELD_DAY_OF_MONTH), "\n";
