<?php

declare(strict_types=1);

$encoded = mb_convert_encoding('あ', 'HTML-ENTITIES', 'UTF-8');
if ('&#12354;' !== $encoded) {
    echo 'encode_fail:', var_export($encoded, true), "\n";
    exit(1);
}

$named = mb_convert_encoding('über', 'HTML-ENTITIES', 'UTF-8');
if ('&uuml;ber' !== $named) {
    echo 'named_fail:', var_export($named, true), "\n";
    exit(1);
}

$ascii = mb_convert_encoding('<>&', 'HTML-ENTITIES', 'UTF-8');
if ('<>&' !== $ascii) {
    echo 'ascii_fail:', var_export($ascii, true), "\n";
    exit(1);
}

$decoded = mb_convert_encoding('&#12354;', 'UTF-8', 'HTML-ENTITIES');
if ('あ' !== $decoded) {
    echo 'decode_fail:', var_export($decoded, true), "\n";
    exit(1);
}

echo "ok\n";
