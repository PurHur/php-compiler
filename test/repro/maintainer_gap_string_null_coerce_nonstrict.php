<?php

foreach (['bin2hex', 'base64_encode', 'quoted_printable_encode', 'quoted_printable_decode'] as $fn) {
    $result = $fn(null);
    if ('' !== $result) {
        echo "{$fn}: expected empty string, got ", var_export($result, true), "\n";
        exit(1);
    }
    echo "{$fn}: ok\n";
}
