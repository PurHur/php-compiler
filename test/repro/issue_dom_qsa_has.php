<?php
// Dom ParentNode CSS :has() relative selectors
// PROFILE=8.4 living Dom (php-src ext/dom/parentnode.c / lexbor; php-src
// ext/dom/tests/modern/css_selectors/pseudo_classes_has.phpt).
$doc = Dom\XMLDocument::createFromString(
    '<root>'
    .'<div id="d1"><p class="foo" id="p1">1</p></div>'
    .'<div id="d2"><p id="p2">2</p></div>'
    .'<div id="d3"></div>'
    .'<section id="sec"><div id="d4"><span id="s1">S</span></div><p id="p3">3</p></section>'
    .'<h1 id="h1">H</h1><h2 id="h2">H2</h2>'
    .'</root>'
);
foreach ([
    'div:has(p.foo)',
    'div:has(p)',
    'div:has(> p)',
    'div:has(> span)',
    'h1:has(+ h2)',
    'div:has(+ p)',
    'div:has(~ p)',
    'section:has(div > span)',
    'div:has(:not(p.foo))',
    ':has(p.foo)',
    'p:has(span)',
    'section:has(p, span)',
    'div:has(p, span)',
    'div:not(:has(p))',
    ':has(> p)',
    'div:has(p.foo):has(p)',
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
$d1 = $doc->querySelector('#d1');
$d2 = $doc->querySelector('#d2');
$d4 = $doc->querySelector('#d4');
$p1 = $doc->querySelector('#p1');
try {
    echo 'matches_d1_has_foo=', $d1->matches('div:has(p.foo)') ? 'yes' : 'no', "\n";
    echo 'matches_d2_has_foo=', $d2->matches('div:has(p.foo)') ? 'yes' : 'no', "\n";
    echo 'matches_p1_has_p=', $p1->matches(':has(p)') ? 'yes' : 'no', "\n";
    echo 'matches_d1_has_child_p=', $d1->matches(':has(> p)') ? 'yes' : 'no', "\n";
    $closest = $d4->closest('section:has(span)');
    echo 'closest=', $closest !== null ? $closest->getAttribute('id') : 'null', "\n";
} catch (DOMException $ex) {
    echo 'matches=EX:', $ex->getMessage(), "\n";
}
foreach ([':has()', ':has', 'div:has(', ':has(())', ':has(>)', 'p:has(> )'] as $bad) {
    try {
        $el = $doc->querySelector($bad);
        echo "bad[$bad]=", $el === null ? 'null' : 'hit', "\n";
    } catch (DOMException $ex) {
        echo "bad[$bad]=", $ex->getMessage(), "\n";
    }
}
