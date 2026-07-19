<?php

declare(strict_types=1);

/** Repro #20782 follow-up — living enum-only / legacy string-only for insertAdjacent*. */
if (!enum_exists('Dom\\AdjacentPosition')) {
    fwrite(STDERR, "fail: Dom\\AdjacentPosition missing (need PHP_COMPILER_PROFILE=8.4)\n");
    exit(1);
}
echo "enum: yes\n";

$d = Dom\HTMLDocument::createEmpty();
$html = $d->createElement('html');
$body = $d->createElement('body');
$d->append($html);
$html->append($body);
$p = $d->createElement('p');
$body->append($p);
$n = $d->createElement('i');
$p->insertAdjacentElement(Dom\AdjacentPosition::AfterBegin, $n);
echo "living-enum: ok\n";

$n2 = $d->createElement('b');
try {
    $p->insertAdjacentElement('beforeend', $n2);
    echo "living-string: ok\n";
} catch (TypeError $e) {
    echo "living-string: TypeError\n";
}

$legacy = new DOMDocument();
$legacy->loadXML('<root><a/></root>');
$el = $legacy->documentElement;
$x = $legacy->createElement('x');
$el->insertAdjacentElement('beforeend', $x);
echo "legacy-string: ok\n";

$y = $legacy->createElement('y');
try {
    $el->insertAdjacentElement(Dom\AdjacentPosition::AfterEnd, $y);
    echo "legacy-enum: ok\n";
} catch (TypeError $e) {
    echo "legacy-enum: TypeError\n";
}
