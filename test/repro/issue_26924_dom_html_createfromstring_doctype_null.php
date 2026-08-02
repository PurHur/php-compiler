<?php
/**
 * Dom\HTMLDocument::createFromString without a doctype leaves doctype null (#26924).
 *
 * Zend 8.4 (lexbor) does not invent HTML 4.0 Transitional; legacy DOMDocument::loadHTML does.
 */
$d = Dom\HTMLDocument::createFromString('<p>x</p>', LIBXML_NOERROR);
echo 'doctype=', $d->doctype ? $d->doctype->name : 'null', "\n";
echo 'save=', var_export($d->saveHtml(), true), "\n";

$d2 = Dom\HTMLDocument::createFromString(
    '<!DOCTYPE html><html><body><p>y</p></body></html>',
    LIBXML_NOERROR
);
echo 'doctype2=', $d2->doctype ? $d2->doctype->name : 'null', "\n";
echo 'public2=', var_export($d2->doctype ? $d2->doctype->publicId : null, true), "\n";
echo 'save2=', var_export($d2->saveHtml(), true), "\n";

$leg = new DOMDocument();
@$leg->loadHTML('<p>z</p>', LIBXML_NOERROR);
echo 'legacy_doctype=', $leg->doctype ? $leg->doctype->name : 'null', "\n";
echo 'legacy_public=', $leg->doctype ? $leg->doctype->publicId : 'n/a', "\n";
