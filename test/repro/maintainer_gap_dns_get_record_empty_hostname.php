<?php

declare(strict_types=1);

$result = dns_get_record('', DNS_A);

if (false === $result) {
    echo "fail: got false expected []\n";
    exit(1);
}
if (!is_array($result)) {
    echo 'fail: got '.gettype($result)." expected array\n";
    exit(1);
}
if ([] !== $result) {
    echo 'fail: got '.var_export($result, true)." expected []\n";
    exit(1);
}

echo "ok\n";
