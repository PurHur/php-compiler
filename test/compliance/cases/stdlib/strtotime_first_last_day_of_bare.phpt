--TEST--
Stdlib: strtotime/modify bare first|last day of + back|front of (#23967)
--FILE--
<?php
$baseTs = strtotime('2020-01-15 12:00:00');
foreach ([
    'last day of',
    'first day of',
    'back of 12',
    'front of 12',
    'last day of this month',
    'first day of this month',
] as $s) {
    $t = strtotime($s, $baseTs);
    echo $s, ' => ', false === $t ? 'false' : date('Y-m-d H:i:s', $t), "\n";
}
$base = new DateTimeImmutable('2020-01-15 12:00:00');
echo 'modify last day of => ', $base->modify('last day of')->format('Y-m-d H:i:s'), "\n";
echo 'modify first day of => ', $base->modify('first day of')->format('Y-m-d H:i:s'), "\n";
--EXPECT--
last day of => 2020-01-31 12:00:00
first day of => 2020-01-01 12:00:00
back of 12 => 2020-01-15 12:15:00
front of 12 => 2020-01-15 11:45:00
last day of this month => 2020-01-31 12:00:00
first day of this month => 2020-01-01 12:00:00
modify last day of => 2020-01-31 12:00:00
modify first day of => 2020-01-01 12:00:00
