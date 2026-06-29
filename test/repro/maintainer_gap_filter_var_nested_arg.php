<?php
/**
 * php-src-strict: filter_var() nested subject + FILTER_* constant (#13617).
 */

$checks = [
  ['filter_var("127.0.0.1", FILTER_VALIDATE_IP)', filter_var('127.0.0.1', FILTER_VALIDATE_IP), '127.0.0.1'],
  ['filter_var(sprintf("%s","127.0.0.1"), FILTER_VALIDATE_IP)', filter_var(sprintf('%s', '127.0.0.1'), FILTER_VALIDATE_IP), '127.0.0.1'],
  ['filter_var(gethostbyname("localhost"), FILTER_VALIDATE_IP)', filter_var(gethostbyname('localhost'), FILTER_VALIDATE_IP), '127.0.0.1'],
];

$h = gethostbyname('localhost');
$checks[] = ['filter_var($h, 275) control', filter_var($h, 275), '127.0.0.1'];

foreach ($checks as [$label, $got, $want]) {
    if ($got === $want) {
        echo "ok: {$label}\n";
        continue;
    }
    echo "fail: {$label} expected ";
    var_export($want);
    echo ', got ';
    var_export($got);
    echo "\n";
    exit(1);
}

echo "done\n";
