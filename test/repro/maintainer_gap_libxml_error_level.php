<?php

declare(strict_types=1);

// libxml_get_errors()[0]->level after DOMDocument::loadXML() failure (#18247, re-#14396).
libxml_use_internal_errors(true);
libxml_clear_errors();

$doc = new DOMDocument();
$ok = $doc->loadXML('<root><unclosed');
if ($ok) {
    fwrite(STDERR, "fail: loadXML should return false\n");
    exit(1);
}

$errors = libxml_get_errors();
if (2 !== count($errors)) {
    fwrite(STDERR, 'fail: expected 2 libxml errors, got '.count($errors)."\n");
    exit(1);
}

$level = $errors[0]->level;
if (LIBXML_ERR_FATAL !== $level) {
    fwrite(STDERR, "fail: expected LIBXML_ERR_FATAL (3), got {$level}\n");
    exit(1);
}

if (73 !== $errors[0]->code) {
    fwrite(STDERR, "fail: expected error code 73, got {$errors[0]->code}\n");
    exit(1);
}

if (LIBXML_ERR_FATAL !== $errors[1]->level) {
    fwrite(STDERR, "fail: expected second error LIBXML_ERR_FATAL (3), got {$errors[1]->level}\n");
    exit(1);
}

if (77 !== $errors[1]->code) {
    fwrite(STDERR, "fail: expected second error code 77, got {$errors[1]->code}\n");
    exit(1);
}

if (!str_contains($errors[1]->message, 'Premature end of data in tag root')) {
    fwrite(STDERR, "fail: unexpected second error message: {$errors[1]->message}\n");
    exit(1);
}

echo "ok level={$level} count=".count($errors)."\n";
