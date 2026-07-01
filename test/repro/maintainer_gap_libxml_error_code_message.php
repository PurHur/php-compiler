<?php

declare(strict_types=1);

/**
 * Issue #14467 — libxml_get_errors() LibXMLError code/message for malformed XML.
 */

libxml_use_internal_errors(true);
libxml_clear_errors();

$d = new DOMDocument();
$ok = @$d->loadXML('<bad>');
if ($ok) {
    fwrite(STDERR, "fail: loadXML should return false\n");
    exit(1);
}

$errors = libxml_get_errors();
if ([] === $errors) {
    fwrite(STDERR, "fail: expected libxml errors\n");
    exit(1);
}

$code = $errors[0]->code;
$message = trim($errors[0]->message);
if (77 !== $code) {
    fwrite(STDERR, "fail: expected code=77, got code={$code}\n");
    exit(1);
}
if ('Premature end of data in tag bad line 1' !== $message) {
    fwrite(STDERR, "fail: unexpected message: {$message}\n");
    exit(1);
}

$last = libxml_get_last_error();
if (false === $last || 77 !== $last->code) {
    fwrite(STDERR, "fail: libxml_get_last_error code mismatch\n");
    exit(1);
}

echo "ok code={$code}\n";
