<?php
// #18912 — default profile must coerce null to "" like Zend 8.2 (ext/standard/string.c, url.c).

$checks = [
    'bin2hex' => static fn () => bin2hex(null),
    'urlencode' => static fn () => urlencode(null),
    'rawurlencode' => static fn () => rawurlencode(null),
];
foreach ($checks as $label => $factory) {
    $result = $factory();
    echo $label, '=', var_export($result, true), "\n";
    if ('' !== $result) {
        echo "fail: expected empty string\n";
        exit(1);
    }
}
echo "ok\n";
