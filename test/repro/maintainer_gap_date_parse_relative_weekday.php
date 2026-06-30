<?php

declare(strict_types=1);

$cases = [
    'next monday',
    'last monday',
    'this monday',
    'monday',
    'next tuesday',
    'last friday',
];

$failed = 0;
foreach ($cases as $input) {
    $p = date_parse($input);
    $year = $p['year'] ?? null;
    $month = $p['month'] ?? null;
    $day = $p['day'] ?? null;
    $relative = $p['relative'] ?? null;
    $line = $input
        .': year='.var_export($year, true)
        .' month='.var_export($month, true)
        .' day='.var_export($day, true)
        .' relative='.json_encode($relative);
    echo $line, "\n";
    if (false !== $year || false !== $month || false !== $day) {
        ++$failed;
    }
    if (!\is_array($relative) || !isset($relative['weekday'])) {
        ++$failed;
    }
}

exit($failed > 0 ? 1 : 0);
