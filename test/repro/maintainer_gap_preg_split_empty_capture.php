<?php

declare(strict_types=1);

$cases = [
    ['/()/', 'ab', ['', 'a', 'b', '']],
    ['/a*/', 'baa', ['', 'b', '', '']],
];

foreach ($cases as [$pattern, $subject, $expected]) {
    $got = preg_split($pattern, $subject);
    if ($got !== $expected) {
        echo "fail: preg_split({$pattern}, {$subject})\n";
        echo '  expected: ', var_export($expected, true), "\n";
        echo '  got:      ', var_export($got, true), "\n";
        exit(1);
    }
}

echo "ok\n";
