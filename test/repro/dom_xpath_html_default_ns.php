<?php

declare(strict_types=1);

/**
 * Repro #26007 — Dom\XPath unprefixed name tests vs HTMLDocument XHTML default NS.
 *
 * Zend/php-src: //div → 0; //h:div after registerNamespace → 1.
 * getElementsByTagName('div') stays HTML-aware → 1.
 */
if (!class_exists('Dom\\HTMLDocument')) {
    fwrite(STDERR, "fail: Dom\\HTMLDocument missing (need PHP_COMPILER_PROFILE=8.4)\n");
    exit(1);
}

$d = Dom\HTMLDocument::createFromString(
    '<!DOCTYPE html><html><body><div id="x">hi</div></body></html>'
);
$xp = new Dom\XPath($d);
$bare = $xp->query('//div')->length;
$xp->registerNamespace('h', 'http://www.w3.org/1999/xhtml');
$pref = $xp->query('//h:div')->length;
$byTag = $d->getElementsByTagName('div')->length;

echo $bare, "\n";
echo $pref, "\n";
echo $byTag, "\n";

if (0 !== $bare || 1 !== $pref || 1 !== $byTag) {
    fwrite(STDERR, "fail: bare={$bare} pref={$pref} bytag={$byTag} (want 0/1/1)\n");
    exit(1);
}
