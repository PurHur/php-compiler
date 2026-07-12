<?php

declare(strict_types=1);

/**
 * Issue #18135 — xml_parse() failures must not populate error_get_last() when suppressed.
 */

$parser = xml_parser_create();
$ok = @xml_parse($parser, '<a><b></a>', true);
if (0 !== $ok) {
    echo "fail: xml_parse() should return 0\n";
    exit(1);
}

if (null !== error_get_last()) {
    echo 'fail: error_get_last populated: ', var_export(error_get_last(), true), "\n";
    exit(1);
}

$parser2 = xml_parser_create();
$values = [];
$index = [];
$status = @xml_parse_into_struct($parser2, '<a><b></a>', $values, $index);
if (0 !== $status) {
    echo "fail: xml_parse_into_struct() should return 0\n";
    exit(1);
}

if (null !== error_get_last()) {
    echo 'fail: error_get_last populated after xml_parse_into_struct: ', var_export(error_get_last(), true), "\n";
    exit(1);
}

echo "ok\n";
