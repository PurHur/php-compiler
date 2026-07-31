<?php

declare(strict_types=1);

/** Repro #20757 — Dom\XPath + Dom\NodeList on living documents. */
if (!class_exists('Dom\\HTMLDocument')) {
    fwrite(STDERR, "fail: Dom\\HTMLDocument missing (need PHP_COMPILER_PROFILE=8.4)\n");
    exit(1);
}
if (!class_exists('Dom\\XPath')) {
    fwrite(STDERR, "fail: Dom\\XPath missing\n");
    exit(1);
}

$doc = Dom\HTMLDocument::createFromString(
    '<html><body><p id="a">hi</p><p class="b">yo</p></body></html>'
);
$xp = new Dom\XPath($doc);
if (!$xp instanceof Dom\XPath) {
    fwrite(STDERR, "fail: construct class\n");
    exit(1);
}

// XHTML default NS: unprefixed //p → 0; register prefix for matches (#26007).
$xp->registerNamespace('h', 'http://www.w3.org/1999/xhtml');
if (0 !== $xp->query('//p')->length) {
    fwrite(STDERR, "fail: bare //p should be 0 on HTMLDocument\n");
    exit(1);
}

$list = $xp->query('//h:p');
if (!$list instanceof Dom\NodeList) {
    fwrite(STDERR, 'fail: query returned '.get_class($list).", expected Dom\\NodeList\n");
    exit(1);
}
if (2 !== $list->length) {
    fwrite(STDERR, "fail: query length={$list->length}\n");
    exit(1);
}
$first = $list->item(0);
if (!$first instanceof Dom\Element && !$first instanceof Dom\HTMLElement) {
    fwrite(STDERR, 'fail: item class='.(null === $first ? 'null' : get_class($first))."\n");
    exit(1);
}

$n = $xp->evaluate('count(//h:p)');
if (2.0 !== (float) $n) {
    fwrite(STDERR, 'fail: evaluate count='.var_export($n, true)."\n");
    exit(1);
}

$xml = Dom\XMLDocument::createFromString('<r xmlns:p="urn:x"><p:a>1</p:a></r>');
$xp2 = new Dom\XPath($xml);
$xp2->registerNamespace('q', 'urn:x');
$nsList = $xp2->query('//q:a');
if (1 !== $nsList->length) {
    fwrite(STDERR, "fail: ns query length={$nsList->length}\n");
    exit(1);
}

if (method_exists(Dom\XPath::class, 'quote')) {
    $q = Dom\XPath::quote("a'b");
    if (!is_string($q) || '' === $q) {
        fwrite(STDERR, "fail: quote empty\n");
        exit(1);
    }
}

try {
    new Dom\XPath(new DOMDocument());
    fwrite(STDERR, "fail: legacy DOMDocument should TypeError\n");
    exit(1);
} catch (TypeError $e) {
    if (!str_contains($e->getMessage(), 'Dom\\Document') && !str_contains($e->getMessage(), 'Document')) {
        fwrite(STDERR, 'fail: TypeError message='.$e->getMessage()."\n");
        exit(1);
    }
}

echo "ok\n";
