<?php
// #20689 Dom ParentNode CSS selector lists (comma) — querySelector/matches/closest
// PROFILE=8.4 living Dom (php-src ext/dom/parentnode.c / lexbor).
$doc = Dom\HTMLDocument::createFromString(
    '<html><body><p id="p">1</p><span id="s">2</span><div class="x"><em id="e">3</em></div></body></html>'
);
$list = $doc->querySelectorAll('p, span');
echo 'qsa=', $list->length;
echo ' first=', $list->item(0)->id;
echo ' second=', $list->item(1)->id, "\n";

$first = $doc->querySelector('span, p');
echo 'qs=', $first->id, "\n";

$p = $doc->getElementById('p');
$s = $doc->getElementById('s');
echo 'matches_p=', $p->matches('span, p') ? 'yes' : 'no', "\n";
echo 'matches_s=', $s->matches('p, span') ? 'yes' : 'no', "\n";
echo 'matches_no=', $p->matches('div, em') ? 'yes' : 'no', "\n";

$em = $doc->getElementById('e');
$closest = $em->closest('div, span');
echo 'closest=', $closest->tagName, "\n";

$compound = $doc->querySelectorAll('div em, span');
echo 'compound=', $compound->length;
echo ' c0=', $compound->item(0)->id;
echo ' c1=', $compound->item(1)->id, "\n";

try {
    $doc->querySelectorAll('p,,span');
    echo "empty_group=ok\n";
} catch (DOMException $e) {
    echo 'empty_group=', $e->getMessage(), "\n";
}
