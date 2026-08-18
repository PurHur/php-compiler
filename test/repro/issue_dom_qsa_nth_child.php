<?php
// Dom ParentNode CSS :nth-child / :nth-of-type — querySelector/matches/closest
// PROFILE=8.4 living Dom (php-src ext/dom/parentnode.c / lexbor).
$doc = Dom\HTMLDocument::createFromString(
    '<html><body><div id="d"><h2 id="h1">1</h2><h2 id="h2">2</h2><h2 id="h3">3</h2><h2 id="h4">4</h2><h2 id="h5">5</h2></div></body></html>'
);
foreach ([
    'h2:nth-child(1)',
    'h2:nth-child(2)',
    'h2:nth-child(odd)',
    'h2:nth-child(even)',
    'h2:nth-child(2n)',
    'h2:nth-child(2n+1)',
    'h2:nth-child(2n + 1)',
    'h2:nth-child(3n-2)',
    'h2:nth-last-child(1)',
    'h2:nth-last-child(2)',
    'h2:nth-of-type(2)',
    'h2:nth-of-type(n+2)',
    'h2:first-of-type',
    'h2:last-of-type',
    'h2:nth-of-type(n+2):nth-last-of-type(n+2)',
] as $sel) {
    try {
        $el = $doc->querySelector($sel);
        $all = $doc->querySelectorAll($sel);
        $ids = [];
        for ($i = 0; $i < $all->length; $i++) {
            $ids[] = $all->item($i)->id;
        }
        echo $sel, '=', $el !== null ? $el->id : 'null', ' [', implode(',', $ids), "]\n";
    } catch (DOMException $ex) {
        echo $sel, '=EX:', $ex->getMessage(), "\n";
    }
}
$h2 = $doc->getElementById('h2');
try {
    echo 'matches_nth2=', $h2->matches('h2:nth-child(2)') ? 'yes' : 'no', "\n";
} catch (DOMException $ex) {
    echo 'matches_nth2=EX:', $ex->getMessage(), "\n";
}
try {
    $c = $h2->closest('div:nth-child(1), h2:nth-child(2)');
    echo 'closest=', $c !== null ? $c->id : 'null', "\n";
} catch (DOMException $ex) {
    echo 'closest=EX:', $ex->getMessage(), "\n";
}
$doc2 = Dom\HTMLDocument::createFromString(
    '<html><body><div id="t">x<p id="p">y</p></div></body></html>'
);
$p = $doc2->getElementById('p');
echo 'text_before_first=', $p->matches(':first-child') ? 'yes' : 'no', "\n";
try {
    echo 'text_before_nth1=', $p->matches(':nth-child(1)') ? 'yes' : 'no', "\n";
} catch (DOMException $ex) {
    echo 'text_before_nth1=EX:', $ex->getMessage(), "\n";
}
foreach ([':nth-child()', ':nth-child(foo)', ':nth-child(2n+)', 'p:bar'] as $bad) {
    try {
        $doc->querySelector($bad);
        echo "bad[$bad]=ok\n";
    } catch (DOMException $ex) {
        echo "bad[$bad]=", $ex->getMessage(), "\n";
    }
}
