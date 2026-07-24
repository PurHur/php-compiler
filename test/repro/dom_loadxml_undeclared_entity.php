<?php
/**
 * Repro #22774 — DOMDocument::loadXML() undeclared entity must fail like Zend/libxml.
 */
libxml_use_internal_errors(true);
libxml_clear_errors();

$d = new DOMDocument();
$ok = $d->loadXML('<r>&foo;</r>');
$errors = libxml_get_errors();

echo 'load=', var_export($ok, true), "\n";
echo 'err_count=', count($errors), "\n";
if (isset($errors[0])) {
    echo 'code=', $errors[0]->code, "\n";
    echo 'level=', $errors[0]->level, "\n";
    echo 'msg=', json_encode(trim($errors[0]->message)), "\n";
}
$save = $d->saveXML();
echo 'save_empty=', var_export('<?xml version="1.0"?>'."\n" === $save, true), "\n";
echo 'no_amp_foo=', var_export(!str_contains($save, '&amp;foo;') && !str_contains($save, '&foo;'), true), "\n";

libxml_clear_errors();
$d2 = new DOMDocument();
$d2->loadXML('<!DOCTYPE r [<!ENTITY e "hi">]><r>&e;</r>');
echo 'declared=', var_export(null !== $d2->documentElement, true), "\n";
