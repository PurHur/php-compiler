<?php

declare(strict_types=1);

// Issue #11884 — DATE_* format constants (ext/date/php_date.c).
$names = [
    'DATE_ATOM',
    'DATE_COOKIE',
    'DATE_ISO8601',
    'DATE_RFC822',
    'DATE_RFC850',
    'DATE_RFC1036',
    'DATE_RFC1123',
    'DATE_RFC7231',
    'DATE_RFC2822',
    'DATE_RFC3339',
    'DATE_W3C',
];
$definedCount = 0;
foreach ($names as $name) {
    if (defined($name)) {
        ++$definedCount;
    }
}
$sample = defined('DATE_RFC3339') ? DATE_RFC3339 : 'MISSING';
echo 'defined_count=', $definedCount, ' sample=', $sample, "\n";
if (11 !== $definedCount) {
    exit(1);
}
$formatted = date(DATE_RFC3339, 0);
if (!is_string($formatted) || !str_contains($formatted, '1970')) {
    echo 'date_fail:', $formatted, "\n";
    exit(1);
}
echo "ok\n";
