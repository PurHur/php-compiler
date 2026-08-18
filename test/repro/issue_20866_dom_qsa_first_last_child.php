<?php
// #20866 Dom ParentNode :first-child / :last-child — querySelector/matches/closest
// PROFILE=8.4 living Dom (php-src ext/dom/parentnode.c / lexbor).
$doc = Dom\HTMLDocument::createFromString(
    '<html><body><div id="d"><p id="a">a</p><p id="b">b</p></div></body></html>'
);
$first = $doc->querySelector('p:first-child');
echo 'first=', $first !== null ? $first->id : 'null', "\n";
$last = $doc->querySelector('p:last-child');
echo 'last=', $last !== null ? $last->id : 'null', "\n";
$a = $doc->getElementById('a');
$b = $doc->getElementById('b');
echo 'matches_a=', $a->matches('p:first-child') ? 'yes' : 'no', "\n";
echo 'matches_b_first=', $b->matches('p:first-child') ? 'yes' : 'no', "\n";
echo 'matches_b_last=', $b->matches(':last-child') ? 'yes' : 'no', "\n";
$closest = $b->closest('div:first-child, p:last-child');
echo 'closest=', $closest !== null ? $closest->id : 'null', "\n";

echo 'nth=', $doc->querySelector('p:nth-child(2)') !== null ? 'yes' : 'no', "\n";
try {
    $doc->querySelector('p:foo');
    echo "unknown=ok\n";
} catch (DOMException $e) {
    echo 'unknown=', $e->getMessage(), "\n";
}

// Text sibling before element still matches :first-child (element siblings only in css selectors).
$doc2 = Dom\HTMLDocument::createFromString(
    '<html><body><div id="t">x<p id="p">y</p></div></body></html>'
);
$p = $doc2->getElementById('p');
echo 'text_before=', $p->matches(':first-child') ? 'yes' : 'no', "\n";
