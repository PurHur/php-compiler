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
if (1 !== count($errors)) {
    fwrite(STDERR, 'fail: expected 1 libxml error, got '.count($errors)."\n");
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

echo "ok level={$level}\n";
