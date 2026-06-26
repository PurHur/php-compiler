<?php

declare(strict_types=1);

$payload = 'not a serialize';
$len = strlen($payload);

var_dump(@unserialize($payload));
$last = error_get_last();
if (!is_array($last)) {
    fwrite(STDERR, "error_get_last() is null after @unserialize\n");
    exit(1);
}
if (!str_contains($last['message'] ?? '', 'Error at offset 0')) {
    fwrite(STDERR, 'unexpected message: '.($last['message'] ?? '')."\n");
    exit(1);
}
if (($last['type'] ?? 0) !== 8) {
    fwrite(STDERR, 'unexpected type: '.($last['type'] ?? '')."\n");
    exit(1);
}

echo "suppressed-ok\n";

var_dump(unserialize($payload));
$last2 = error_get_last();
$msg2 = is_array($last2) && is_string($last2['message'] ?? null) ? $last2['message'] : '';
if (!str_contains($msg2, "of {$len} bytes")) {
    fwrite(STDERR, 'unexpected unsuppressed last error: '.var_export($last2, true)."\n");
    exit(1);
}

echo "unsuppressed-ok\n";
