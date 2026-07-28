--TEST--
Stdlib: DateTimeImmutable::modify / strtotime back|front of YYYY-MM-DD (#24395)
--FILE--
<?php
declare(strict_types=1);
$base = new DateTimeImmutable('2024-01-31 10:00:00');
echo $base->modify('back of 2024-01-15')->format('Y-m-d H:i:s'), "\n";
echo $base->modify('front of 2024-01-15')->format('Y-m-d H:i:s'), "\n";
echo $base->modify('back of 1999-01-15')->format('Y-m-d H:i:s'), "\n";
echo $base->modify('front of 1999-01-15')->format('Y-m-d H:i:s'), "\n";
echo $base->modify('back of 9 2024-01-15')->format('Y-m-d H:i:s'), "\n";
echo $base->modify('back of 12')->format('Y-m-d H:i:s'), "\n";
echo date('Y-m-d H:i:s', strtotime('back of 2024-01-15', $base->getTimestamp())), "\n";
echo date('Y-m-d H:i:s', strtotime('front of 2024-01-15', $base->getTimestamp())), "\n";
?>
--EXPECT--
2024-01-15 20:15:00
2024-01-15 19:45:00
1999-01-15 19:15:00
1999-01-15 18:45:00
2024-01-15 09:15:00
2024-01-31 12:15:00
2024-01-15 20:15:00
2024-01-15 19:45:00
