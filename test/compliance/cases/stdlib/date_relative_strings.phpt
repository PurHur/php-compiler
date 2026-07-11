--TEST--
stdlib strtotime()/date_create()/DateTime — relative modification strings (#11327)
--FILE--
<?php
declare(strict_types=1);

$cases = [
    '2024-01-31 +1 month' => '2024-03-02',
    'last day of February 2024' => '2024-02-29',
    'first day of January 2024' => '2024-01-01',
];
foreach ($cases as $input => $expectedDate) {
    $ts = strtotime($input);
    if (false === $ts) {
        echo "strtotime fail: {$input}\n";
        exit(1);
    }
    echo date('Y-m-d', $ts), "\n";
    $created = date_create($input);
    if (false === $created) {
        echo "date_create fail: {$input}\n";
        exit(1);
    }
    echo $created->format('Y-m-d'), "\n";
}
echo (new DateTime('2024-01-31 +1 month'))->format('Y-m-d'), "\n";
--EXPECT--
2024-03-02
2024-03-02
2024-02-29
2024-02-29
2024-01-01
2024-01-01
2024-03-02
