<?php

declare(strict_types=1);

$fail = 0;

$cases = [
    ['2020-06-15 UTC', 1592179200],
    ['2020-01-01T00:00:00+00:00', 1577836800],
    ['15 June 2020', 1592179200],
    ['June 15, 2020', 1592179200],
];

foreach ($cases as $case) {
    $input = $case[0];
    $want = $case[1];
    $got = strtotime($input);
    if ($got !== $want) {
        echo "fail strtotime({$input}): got ";
        var_export($got);
        echo " want {$want}\n";
        ++$fail;
    }
}

$dt = date_create('2020-06-15 UTC');
if (false === $dt) {
    echo "fail date_create(2020-06-15 UTC)\n";
    ++$fail;
} elseif ($dt->getTimestamp() !== 1592179200) {
    echo "fail date_create timestamp\n";
    ++$fail;
}

exit($fail === 0 ? 0 : 1);
