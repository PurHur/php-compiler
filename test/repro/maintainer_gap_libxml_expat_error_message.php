<?php

declare(strict_types=1);

/**
 * Issue #18138 — libxml_get_last_error()->message uses XML_ErrorString detail, not short map text.
 */

libxml_use_internal_errors(true);
libxml_clear_errors();

$parser = xml_parser_create();
xml_parse($parser, '<a><b></a>', true);

$last = libxml_get_last_error();
if (!is_object($last)) {
    echo 'fail: expected LibXMLError object', "\n";
    exit(1);
}

if (!str_contains($last->message, 'Opening and ending tag mismatch: b line 0 and a')) {
    echo 'fail: message mismatch: ', var_export($last->message, true), "\n";
    exit(1);
}

// xml_error_string() short map unchanged for procedural API (#18138 done-when).
$parser2 = xml_parser_create();
xml_parse($parser2, '<a><b></a>', true);
$str = xml_error_string(xml_get_error_code($parser2));
if ('Mismatched tag' !== $str) {
    echo 'fail: xml_error_string should remain short map, got ', var_export($str, true), "\n";
    exit(1);
}

echo "ok\n";
