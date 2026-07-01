<?php

$result = unserialize(null);
$last = error_get_last();

if (false !== $result) {
    echo 'fail: unserialize(null) expected false, got ', var_export($result, true), "\n";
    exit(1);
}

$expectedDep = 'unserialize(): Passing null to parameter #1 ($data) of type string is deprecated';
$message = $last['message'] ?? null;
if ($expectedDep !== $message) {
    echo 'fail: deprecation got ', var_export($message, true), "\n";
    exit(1);
}

echo "ok\n";
