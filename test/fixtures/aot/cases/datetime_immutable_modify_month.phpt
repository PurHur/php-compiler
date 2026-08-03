--TEST--
AOT: DateTimeImmutable::modify('+1 month') / '+1 year' match Zend (#27262)
--FILE--
<?php
$di = new DateTimeImmutable('2024-01-01');
echo $di->modify('+1 month')->format('Y-m-d'), "\n";
$dt = new DateTime('2024-01-31');
echo $dt->modify('+1 month')->format('Y-m-d'), "\n";
echo $di->modify('+1 year')->format('Y-m-d'), "\n";
--EXPECT--
2024-02-01
2024-03-02
2025-01-01
