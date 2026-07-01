<?php

declare(strict_types=1);

/**
 * Issue #14396 — libxml_get_errors()[0]->level must be LIBXML_ERR_FATAL (3) for unclosed start tag.
 */

libxml_use_internal_errors(true);
libxml_clear_errors();

$doc = new DOMDocument();
$ok = $doc->loadXML('<root><unclosed');
if ($ok) {
    fwrite(STDERR, "fail: loadXML should return false\n");
    exit(1);
}

$errors = libxml_get_errors();
if ([] === $errors) {
    fwrite(STDERR, "fail: expected libxml errors after DOM loadXML failure\n");
    exit(1);
}

$level = $errors[0]->level;
$code = $errors[0]->code;
if (LIBXML_ERR_FATAL !== $level) {
    fwrite(STDERR, 'fail: expected level='.LIBXML_ERR_FATAL.", got level={$level}\n");
    exit(1);
}
if (73 !== $code) {
    fwrite(STDERR, "fail: expected code=73, got code={$code}\n");
    exit(1);
}

echo "ok level={$level} code={$code}\n";
