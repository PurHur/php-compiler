<?php

declare(strict_types=1);

/** Repro #20782 — Dom\AdjacentPosition enum + insertAdjacent* enum where. */
if (!enum_exists('Dom\\AdjacentPosition')) {
    fwrite(STDERR, "fail: Dom\\AdjacentPosition missing (need PHP_COMPILER_PROFILE=8.4)\n");
    exit(1);
}
echo 'enum: yes'."\n";
echo 'case: '.Dom\AdjacentPosition::BeforeBegin->value."\n";

$d = Dom\HTMLDocument::createFromString('<p id="p">hi</p>');
$p = $d->getElementById('p');
$n = $d->createElement('i');
$p->insertAdjacentElement(Dom\AdjacentPosition::BeforeBegin, $n);
echo "insert: ok\n";
$body = $d->body;
echo 'tag: '.$body->firstElementChild->tagName."\n";

$p2 = $d->getElementById('p');
$p2->insertAdjacentText(Dom\AdjacentPosition::AfterBegin, 'X');
$html = '<b>Y</b>';
$p2->insertAdjacentHTML(Dom\AdjacentPosition::BeforeEnd, $html);
echo "text_html: ok\n";

$legacy = new DOMDocument();
$legacy->loadHTML('<div id="d">z</div>', LIBXML_NOERROR);
$el = $legacy->getElementById('d');
$span = $legacy->createElement('span');
$el->insertAdjacentElement(Dom\AdjacentPosition::AfterEnd, $span);
echo "legacy: ok\n";
