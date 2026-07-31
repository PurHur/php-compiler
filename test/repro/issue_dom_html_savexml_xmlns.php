<?php
/**
 * #26025 — Dom\HTMLDocument::saveXml() emits default XHTML xmlns on HTML elements.
 * saveHtml() stays without xmlns; nested children inherit and do not re-emit.
 */
if (!class_exists('Dom\\HTMLDocument')) {
    fwrite(STDERR, "fail: Dom\\HTMLDocument missing (need PHP_COMPILER_PROFILE=8.4) (#26025)\n");
    exit(1);
}

$xhtml = 'http://www.w3.org/1999/xhtml';
$h = Dom\HTMLDocument::createFromString(
    '<!doctype html><html><body><div id=d><span>s</span></div></body></html>'
);
$d = $h->getElementById('d');
if (null === $d) {
    fwrite(STDERR, "fail: getElementById\n");
    exit(1);
}

$xml = $h->saveXml($d);
$html = $h->saveHtml($d);

if (!str_contains($xml, 'xmlns="'.$xhtml.'"')) {
    fwrite(STDERR, "fail: saveXml missing xmlns: ".$xml."\n");
    exit(1);
}
if (!str_starts_with($xml, '<div xmlns="'.$xhtml.'" id="d">')
    && !str_starts_with($xml, '<div xmlns="'.$xhtml.'" id=\'d\'>')) {
    // Attribute order: xmlns before id (nsDef first) — Zend order.
    if (!preg_match('/^<div\s+xmlns="'.preg_quote($xhtml, '/').'"\s+id="d">/', $xml)
        && !preg_match('/^<div\s+id="d"\s+xmlns="'.preg_quote($xhtml, '/').'">/', $xml)) {
        fwrite(STDERR, "fail: unexpected saveXml shape: ".$xml."\n");
        exit(1);
    }
}
if (substr_count($xml, 'xmlns="'.$xhtml.'"') !== 1) {
    fwrite(STDERR, "fail: nested child re-emitted xmlns: ".$xml."\n");
    exit(1);
}
if (str_contains($html, 'xmlns=')) {
    fwrite(STDERR, "fail: saveHtml must omit xmlns: ".$html."\n");
    exit(1);
}
if ($html !== '<div id="d"><span>s</span></div>') {
    fwrite(STDERR, "fail: unexpected saveHtml: ".$html."\n");
    exit(1);
}

// Explicit XML xmlns path unchanged.
$x = Dom\XMLDocument::createFromString('<root xmlns="urn:x"><child/></root>');
$xOut = $x->saveXml($x->documentElement);
if (!str_contains($xOut, 'xmlns="urn:x"')) {
    fwrite(STDERR, "fail: XMLDocument xmlns lost: ".$xOut."\n");
    exit(1);
}

echo "ok\n";
