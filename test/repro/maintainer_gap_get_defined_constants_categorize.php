<?php

declare(strict_types=1);

$c = get_defined_constants(true);
foreach (['Core', 'standard', 'date', 'json', 'SPL'] as $m) {
    echo $m, ': ', isset($c[$m]) ? (string) count($c[$m]) : 'missing', "\n";
}

$userCount = isset($c['user']) ? count($c['user']) : 0;
echo 'user: ', (string) $userCount, "\n";

foreach ($c as $k => $v) {
    // Must not fatal (issue #4840 Unknown index type 7).
}

foreach (['STDIN', 'STDOUT', 'STDERR'] as $name) {
    $bucket = 'missing';
    foreach ($c as $mod => $consts) {
        if (isset($consts[$name])) {
            $bucket = $mod;
            break;
        }
    }
    echo $name, '_bucket=', $bucket, "\n";
}

echo isset($c['standard']['PHP_EOL']) ? "standard_has_php_eol\n" : "standard_missing_php_eol\n";
echo "foreach_ok\n";
