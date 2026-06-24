<?php

declare(strict_types=1);

$empty = chunk_split('');
$explicit = chunk_split('', 1, '');
$control = chunk_split('ab', 1);

if ("\r\n" !== $empty) {
    fwrite(STDERR, 'FAIL: chunk_split("") expected "\\r\\n", got '.var_export($empty, true)."\n");
    exit(1);
}
if ('' !== $explicit) {
    fwrite(STDERR, 'FAIL: chunk_split("", 1, "") expected "", got '.var_export($explicit, true)."\n");
    exit(1);
}
if ("a\r\nb\r\n" !== $control) {
    fwrite(STDERR, 'FAIL: chunk_split("ab", 1) control mismatch: '.var_export($control, true)."\n");
    exit(1);
}

echo "OK\n";
