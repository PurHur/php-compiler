<?php

declare(strict_types=1);

$cases = [
    '2024-01-31 +1 month' => '2024-03-02',
    'last day of February 2024' => '2024-02-29',
    'first day of January 2024' => '2024-01-01',
];

$fail = 0;
foreach ($cases as $input => $expectedDate) {
    $ts = strtotime($input);
    if (false === $ts) {
        fwrite(STDERR, "strtotime({$input}) => false\n");
        ++$fail;
        continue;
    }
    $actual = date('Y-m-d', $ts);
    if ($expectedDate !== $actual) {
        fwrite(STDERR, "strtotime({$input}) => {$actual}, expected {$expectedDate}\n");
        ++$fail;
    }
    $created = date_create($input);
    if (false === $created) {
        fwrite(STDERR, "date_create({$input}) => false\n");
        ++$fail;
        continue;
    }
    $createdDate = $created->format('Y-m-d');
    if ($expectedDate !== $createdDate) {
        fwrite(STDERR, "date_create({$input}) => {$createdDate}, expected {$expectedDate}\n");
        ++$fail;
    }
}

$dt = new DateTime('2024-01-31 +1 month');
if ('2024-03-02' !== $dt->format('Y-m-d')) {
    fwrite(STDERR, 'DateTime ctor => '.$dt->format('Y-m-d')."\n");
    ++$fail;
}

echo 0 === $fail ? "ok\n" : "fail\n";
exit(0 === $fail ? 0 : 1);
