<?php
// #25190 — IntlCalendar::createInstance FIELD_MILLISECOND from wall clock
$before = (int) floor(microtime(true) * 1000);
$c = IntlCalendar::createInstance('UTC');
$after = (int) ceil(microtime(true) * 1000);
$t = (int) $c->getTime();
$ms = $c->get(IntlCalendar::FIELD_MILLISECOND);
echo 'ms=', $ms, ' t%1000=', $t % 1000, "\n";
echo 'in_window=', ($t >= $before && $t <= $after) ? 'yes' : 'no', "\n";
$c->set(2020, 5, 15, 12, 30, 45);
echo 'preserved=', ($c->get(IntlCalendar::FIELD_MILLISECOND) === $ms) ? 'yes' : 'no', "\n";
