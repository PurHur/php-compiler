--TEST--
DateTime::setDate(null,…) year 0 / −0001 like Zend (#31619, ext/date/php_date.c)
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$d = new DateTime('2024-06-15');
$d->setDate(null, null, null);
echo 'DateTime::setDate(null,null,null)=', $d->format('Y-m-d'), "\n";
$i = new DateTimeImmutable('2024-06-15');
echo 'DateTimeImmutable::setDate(null,null,null)=', $i->setDate(null, null, null)->format('Y-m-d'), "\n";
$y = new DateTime('2024-06-15');
$y->setDate(null, 6, 15);
echo 'DateTime::setDate(null,6,15)=', $y->format('Y-m-d'), "\n";
--EXPECT--
DateTime::setDate(null,null,null)=-0001-11-30
DateTimeImmutable::setDate(null,null,null)=-0001-11-30
DateTime::setDate(null,6,15)=0000-06-15
