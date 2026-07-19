<?php
declare(strict_types=1);

/**
 * #20830 — importNode HTML id into XML target must reindex getElementById (re-#19212).
 * php-src: ext/dom/document.c importNode + ext/dom/node.c ID table / attr atype copy.
 */
$src = new DOMDocument();
$src->loadHTML('<html><body><div id="x">z</div></body></html>');
$el = $src->getElementById('x');
if (null === $el) {
    echo "fail_src\n";
    exit(1);
}

$dst = new DOMDocument('1.0', 'UTF-8');
$dst->appendChild($dst->createElement('root'));
$n = $dst->importNode($el, true);
$dst->documentElement->appendChild($n);

$found = $dst->getElementById('x');
if (null === $found || 'z' !== $found->textContent) {
    echo "null\n";
    exit(1);
}

// setIdAttribute must NOT survive importNode (Zend / php-src).
$src2 = new DOMDocument();
$src2->loadXML('<root><div xid="y">w</div></root>');
$el2 = $src2->getElementsByTagName('div')->item(0);
$el2->setIdAttribute('xid', true);
$dst2 = new DOMDocument('1.0', 'UTF-8');
$dst2->appendChild($dst2->createElement('root'));
$n2 = $dst2->importNode($el2, true);
$dst2->documentElement->appendChild($n2);
if (null !== $dst2->getElementById('y')) {
    echo "setid_leak\n";
    exit(1);
}

echo "ok\n";
