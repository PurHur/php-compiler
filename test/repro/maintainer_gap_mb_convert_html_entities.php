<?php

declare(strict_types=1);

$encoded = mb_convert_encoding('über', 'HTML-ENTITIES', 'UTF-8');
if ('&uuml;ber' !== $encoded) {
    echo 'encode_fail:', var_export($encoded, true), "\n";
    exit(1);
}

$decoded = mb_convert_encoding('&uuml;ber', 'UTF-8', 'HTML-ENTITIES');
if ('über' !== $decoded) {
    echo 'decode_fail:', var_export($decoded, true), "\n";
    exit(1);
}

echo "ok\n";
