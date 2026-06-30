<?php

declare(strict_types=1);

if (!function_exists('libxml_get_last_error')) {
    fwrite(STDERR, "fail: libxml_get_last_error() undefined\n");
    exit(1);
}

libxml_use_internal_errors(true);
libxml_clear_errors();

$empty = libxml_get_last_error();
if (false !== $empty) {
    fwrite(STDERR, 'fail: expected false on empty buffer, got '.var_export($empty, true)."\n");
    exit(1);
}

$parser = xml_parser_create();
xml_parse($parser, '<unclosed', true);

$last = libxml_get_last_error();
if (!is_object($last) || !($last instanceof LibXMLError)) {
    fwrite(STDERR, 'fail: expected LibXMLError object, got '.var_export($last, true)."\n");
    exit(1);
}

$errors = libxml_get_errors();
if (1 !== count($errors)) {
    fwrite(STDERR, 'fail: expected one buffered error, got '.count($errors)."\n");
    exit(1);
}
if ($last->code !== $errors[0]->code || $last->message !== $errors[0]->message) {
    fwrite(STDERR, "fail: last error fields mismatch buffered head\n");
    exit(1);
}

libxml_clear_errors();
if (false !== libxml_get_last_error()) {
    fwrite(STDERR, "fail: expected false after clear\n");
    exit(1);
}

echo "ok\n";
