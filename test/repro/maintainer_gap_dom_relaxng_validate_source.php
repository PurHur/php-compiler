<?php
declare(strict_types=1);

/** Repro #20235 — DOMDocument::relaxNGValidateSource() valid RNG (php-src ext/dom/document.c). */
$doc = new DOMDocument();
$doc->loadXML('<root><child/></root>');
$rng = '<?xml version="1.0"?><grammar xmlns="http://relaxng.org/ns/structure/1.0">'
    . '<start><element name="root"><zeroOrMore><element name="child"><empty/></element></zeroOrMore></element></start>'
    . '</grammar>';
libxml_use_internal_errors(true);
libxml_clear_errors();
$ok = $doc->relaxNGValidateSource($rng);
var_export($ok);
echo "\n";
echo count(libxml_get_errors()), "\n";

$badDoc = new DOMDocument();
$badDoc->loadXML('<root><other/></root>');
libxml_clear_errors();
$bad = $badDoc->relaxNGValidateSource($rng);
var_export($bad);
echo "\n";
$errs = libxml_get_errors();
echo count($errs), "\n";
if (isset($errs[0])) {
    echo trim($errs[0]->message), "\n";
}
