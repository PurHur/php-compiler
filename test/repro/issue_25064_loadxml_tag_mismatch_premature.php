<?php
/**
 * #25064 — loadXML('<r><a></r>') must yield two libxml FATALS (76 then 77) with line=1.
 * php-src: ext/dom/document.c + ext/libxml/libxml.c
 */
libxml_use_internal_errors(true);
libxml_clear_errors();
$doc = new DOMDocument();
$ok = $doc->loadXML('<r><a></r>');
$errors = libxml_get_errors();
if (false !== $ok) {
    fwrite(STDERR, "expected loadXML false, got ".var_export($ok, true)."\n");
    exit(1);
}
if (2 !== count($errors)) {
    fwrite(STDERR, 'expected 2 errors, got '.count($errors)."\n");
    exit(1);
}
if (76 !== $errors[0]->code || 77 !== $errors[1]->code) {
    fwrite(STDERR, "expected codes 76 then 77, got {$errors[0]->code} then {$errors[1]->code}\n");
    exit(1);
}
if (1 !== $errors[0]->line || 1 !== $errors[1]->line) {
    fwrite(STDERR, "expected line=1, got {$errors[0]->line} and {$errors[1]->line}\n");
    exit(1);
}
if (!str_contains($errors[0]->message, 'Opening and ending tag mismatch: a line 1 and r')) {
    fwrite(STDERR, "bad mismatch message: {$errors[0]->message}\n");
    exit(1);
}
if (!str_contains($errors[1]->message, 'Premature end of data in tag r line 1')) {
    fwrite(STDERR, "bad premature message: {$errors[1]->message}\n");
    exit(1);
}
echo "ok\n";
