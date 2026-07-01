<?php

declare(strict_types=1);

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
if (LIBXML_ERR_FATAL !== $level) {
    fwrite(STDERR, "fail: expected level=".LIBXML_ERR_FATAL.", got level={$level}\n");
    exit(1);
}

echo "ok level={$level}\n";
