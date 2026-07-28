--TEST--
Stdlib: strtotime/modify first|last day of ±N month|year (#23987)
--FILE--
<?php
$baseTs = strtotime('2024-01-31 12:00:00');
foreach ([
    'first day of +1 month',
    'last day of +1 month',
    'first day of +2 months',
    'last day of -1 month',
    'first day of +1 year',
] as $s) {
    $t = strtotime($s, $baseTs);
    echo $s, ' => ', false === $t ? 'false' : date('Y-m-d H:i:s', $t), "\n";
}
$base = new DateTimeImmutable('2024-01-31 12:00:00');
echo 'modify first day of +1 month => ', $base->modify('first day of +1 month')->format('Y-m-d H:i:s'), "\n";
echo 'modify last day of +1 month => ', $base->modify('last day of +1 month')->format('Y-m-d H:i:s'), "\n";
echo 'control first day of next month => ', date('Y-m-d H:i:s', strtotime('first day of next month', $baseTs)), "\n";
--EXPECT--
first day of +1 month => 2024-02-01 12:00:00
last day of +1 month => 2024-02-29 12:00:00
first day of +2 months => 2024-03-01 12:00:00
last day of -1 month => 2023-12-31 12:00:00
first day of +1 year => 2025-01-01 12:00:00
modify first day of +1 month => 2024-02-01 12:00:00
modify last day of +1 month => 2024-02-29 12:00:00
control first day of next month => 2024-02-01 12:00:00
