<?php

declare(strict_types=1);

/**
 * Issue #18146 — libxml_get_last_error() after failed xml_parse() without libxml_use_internal_errors(true).
 */

libxml_clear_errors();

$parser = xml_parser_create();
xml_parse($parser, '<a><b></a>', true);

$last = libxml_get_last_error();
if (!is_object($last) || !($last instanceof LibXMLError)) {
    echo 'fail: expected LibXMLError, got ', var_export($last, true), "\n";
    exit(1);
}

if (76 !== $last->code) {
    echo "fail: expected error code 76, got {$last->code}\n";
    exit(1);
}

if (!str_contains($last->message, 'Opening and ending tag mismatch')) {
    echo 'fail: unexpected message: ', $last->message, "\n";
    exit(1);
}

echo "ok\n";
