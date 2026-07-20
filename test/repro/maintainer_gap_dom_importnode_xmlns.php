<?php
/** Repro #21482 — importNode must materialize ancestor xmlns on saveXML. */
error_reporting(E_ALL);

$src = new DOMDocument();
$src->loadXML('<a xmlns:p="urn:p" xmlns:q="urn:q"><p:b p:x="1"><q:c/></p:b></a>');
$dst = new DOMDocument();
$dst->loadXML('<root/>');
$imp = $dst->importNode($src->documentElement->firstChild, true);
$xml = $dst->saveXML($imp);

$ok = str_contains($xml, 'xmlns:p="urn:p"')
    && str_contains($xml, 'xmlns:q="urn:q"')
    && str_contains($xml, 'p:x="1"');

// default xmlns from ancestor
$src2 = new DOMDocument();
$src2->loadXML('<a xmlns="urn:d"><b/></a>');
$dst2 = new DOMDocument();
$dst2->loadXML('<root/>');
$imp2 = $dst2->importNode($src2->documentElement->firstChild, true);
$xml2 = $dst2->saveXML($imp2);
$ok = $ok && str_contains($xml2, 'xmlns="urn:d"');

// in-subtree decl must stay on child, not only root
$src3 = new DOMDocument();
$src3->loadXML('<a xmlns:p="urn:p"><p:b><q:c xmlns:q="urn:q"/></p:b></a>');
$dst3 = new DOMDocument();
$dst3->loadXML('<root/>');
$imp3 = $dst3->importNode($src3->documentElement->firstChild, true);
$xml3 = $dst3->saveXML($imp3);
$ok = $ok
    && str_contains($xml3, 'xmlns:p="urn:p"')
    && str_contains($xml3, 'xmlns:q="urn:q"');

echo $ok ? "ok\n" : ("fail xml=" . $xml . " xml2=" . $xml2 . " xml3=" . $xml3 . "\n");
exit($ok ? 0 : 1);
