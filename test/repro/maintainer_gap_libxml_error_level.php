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
    fwrite(STDERR, "loadXML should fail\n");
    exit(1);
}

$errors = libxml_get_errors();
if ([] === $errors) {
    fwrite(STDERR, "expected libxml errors\n");
    exit(1);
}

$level = $errors[0]->level;
$code = $errors[0]->code;
if (3 !== $level) {
    fwrite(STDERR, "level={$level} expected 3 (LIBXML_ERR_FATAL)\n");
    exit(1);
}
if (73 !== $code) {
    fwrite(STDERR, "code={$code} expected 73 (XML_ERR_TAG_NOT_FINISHED)\n");
    exit(1);
}

echo "ok level={$level} code={$code}\n";
