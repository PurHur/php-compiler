--TEST--
stdlib IntlCalendar::createInstance initializes FIELD_MILLISECOND from wall clock (#25190)
--SKIPIF--
<?php
if (!extension_loaded('intl') || !class_exists('IntlCalendar')) {
    echo 'skip intl calendar required';
}
--FILE--
<?php
declare(strict_types=1);

$before = (int) floor(microtime(true) * 1000);
$c = IntlCalendar::createInstance('UTC');
$after = (int) ceil(microtime(true) * 1000);
$t = (int) $c->getTime();
$ms = $c->get(IntlCalendar::FIELD_MILLISECOND);
echo (int) ($ms === ($t % 1000)), "\n";
echo (int) ($t >= $before && $t <= $after), "\n";

// 6-arg set preserves prior milliseconds (Zend / ICU).
$preserved = $c->get(IntlCalendar::FIELD_MILLISECOND);
$c->set(2020, 5, 15, 12, 30, 45);
echo (int) ($c->get(IntlCalendar::FIELD_MILLISECOND) === $preserved), "\n";

// Explicit setTime / FIELD_MILLISECOND still work.
$c->setTime(1500.0);
echo (int) $c->get(IntlCalendar::FIELD_MILLISECOND), "\n";
$c->set(IntlCalendar::FIELD_MILLISECOND, 123);
echo (int) $c->get(IntlCalendar::FIELD_MILLISECOND), "\n";
--EXPECT--
1
1
1
500
123
