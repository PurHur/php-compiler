<?php
/**
 * Repro #21125 — DOMXPath local-name()/name() + unprefixed //x + //* document element.
 * Zend/php-src ext/dom/xpath.c (libxml xmlXPathEvalExpression).
 */
$d = new DOMDocument();
$d->loadXML('<?xml version="1.0"?><r xmlns:a="urn:a"><a:x>hit</a:x><x>miss</x><y>keep</y></r>');
$xp = new DOMXPath($d);
foreach ([
    '//*',
    '//x',
    '//*[local-name()="x"]',
    '//*[local-name()="y"]',
    '//*[name()="x"]',
    '//*[name()="a:x"]',
] as $q) {
    $n = $xp->query($q);
    echo $q, ' => ', ($n === false ? 'false' : $n->length), "\n";
}
echo 'rel=', $xp->query('.//*', $d->documentElement)->length, "\n";
