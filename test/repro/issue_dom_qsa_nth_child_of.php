<?php
// Dom ParentNode CSS :nth-child(An+B of S) / :nth-last-child(An+B of S)
// PROFILE=8.4 living Dom (php-src ext/dom/parentnode.c / lexbor; php-src
// ext/dom/tests/modern/css_selectors/pseudo_classes_nth_child_of.phpt).
$doc = Dom\XMLDocument::createFromString(
    '<container>'
    .'<h2 class="hi" id="h1">1</h2>'
    .'<h2 id="h2">2</h2>'
    .'<h2 class="hi" id="h3">3</h2>'
    .'<h2 class="hi" id="h4">4</h2>'
    .'<h2 id="h5">5</h2>'
    .'<h2 class="hi" id="h6">6</h2>'
    .'</container>'
);
foreach ([
    'h2:nth-child(even of .hi)',
    'h2.hi:nth-child(even)',
    'h2:nth-child(odd of .hi)',
    'h2.hi:nth-child(odd)',
    'h2:nth-last-child(even of .hi)',
    'h2.hi:nth-last-child(even)',
    'h2:nth-last-child(odd of .hi)',
    'h2.hi:nth-last-child(odd)',
    'h2:nth-child(2n of .hi)',
    'h2:nth-child(2n+1 of .hi)',
    'h2:nth-child(n of .hi)',
    'h2:nth-child(even of h2.hi)',
    ':nth-child(even of .hi)',
] as $sel) {
    try {
        $el = $doc->querySelector($sel);
        $all = $doc->querySelectorAll($sel);
        $ids = [];
        for ($i = 0; $i < $all->length; $i++) {
            $n = $all->item($i);
            $ids[] = $n->hasAttribute('id') && $n->getAttribute('id') !== ''
                ? $n->getAttribute('id')
                : $n->nodeName;
        }
        echo $sel, '=', $el !== null
            ? ($el->hasAttribute('id') && $el->getAttribute('id') !== '' ? $el->getAttribute('id') : $el->nodeName)
            : 'null',
            ' [', implode(',', $ids), "]\n";
    } catch (DOMException $ex) {
        echo $sel, '=EX:', $ex->getMessage(), "\n";
    }
}
$h3 = $doc->querySelector('#h3');
$h4 = $doc->querySelector('#h4');
try {
    echo 'matches_h3_even_of=', $h3->matches('h2:nth-child(even of .hi)') ? 'yes' : 'no', "\n";
    echo 'matches_h4_even_of=', $h4->matches('h2:nth-child(even of .hi)') ? 'yes' : 'no', "\n";
    echo 'matches_h3_even=', $h3->matches('h2.hi:nth-child(even)') ? 'yes' : 'no', "\n";
    $closest = $h3->closest(':nth-child(even of .hi)');
    echo 'closest=', $closest !== null ? $closest->getAttribute('id') : 'null', "\n";
} catch (DOMException $ex) {
    echo 'matches=EX:', $ex->getMessage(), "\n";
}
foreach ([
    ':nth-child(even of)',
    ':nth-child(of .hi)',
    ':nth-child(even of ())',
    ':nth-of-type(even of .hi)',
] as $bad) {
    try {
        $el = $doc->querySelector($bad);
        echo "bad[$bad]=", $el === null ? 'null' : 'hit', "\n";
    } catch (DOMException $ex) {
        echo "bad[$bad]=", $ex->getMessage(), "\n";
    }
}
