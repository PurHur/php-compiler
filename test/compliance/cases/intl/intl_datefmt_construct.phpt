--TEST--
IntlDateFormatter::__construct matches create() (#21097)
--FILE--
<?php
$ts = 1592179200;
$a = new IntlDateFormatter('en_US', IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC');
$b = IntlDateFormatter::create('en_US', IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, 'UTC');
echo 'new_format=', $a->format($ts), "\n";
echo 'create_format=', $b->format($ts), "\n";
echo 'new_pattern=', $a->getPattern(), "\n";
echo 'create_pattern=', $b->getPattern(), "\n";
echo 'new_locale=', $a->getLocale(), "\n";
echo 'new_dateType=', $a->getDateType(), "\n";
$c = new IntlDateFormatter('en_US', IntlDateFormatter::FULL, IntlDateFormatter::FULL, null, null, 'yyyy-MM-dd');
echo 'pattern_format=', $c->format(0), "\n";
echo 'pattern_get=', $c->getPattern(), "\n";
?>
--EXPECT--
new_format=Jun 15, 2020
create_format=Jun 15, 2020
new_pattern=MMM d, y
create_pattern=MMM d, y
new_locale=en_US
new_dateType=2
pattern_format=1970-01-01
pattern_get=yyyy-MM-dd
