<?php
// Dom ParentNode CSS :not / :is / :where
// PROFILE=8.4 living Dom (php-src ext/dom/parentnode.c / lexbor; #32150).
$doc = Dom\XMLDocument::createFromString(
    '<root>'
    .'<article id="art"><p id="p1">1</p><span id="s1" class="foo">S</span></article>'
    .'<main id="main"><p id="p2">2</p></main>'
    .'<section id="sec"><p id="p3">3</p></section>'
    .'<div id="d1"><p class="foo" id="pf">F</p></div>'
    .'<div id="d2"><p id="pn">N</p></div>'
    .'</root>'
);
foreach ([
    ':not(p)',
    'p:not(.foo)',
    ':not(.foo)',
    'p:not(#p1)',
    ':is(article, main)',
    ':is(article, main) p',
    ':where(article, main) p',
    ':not(article, main, section)',
    'p:not(:first-child)',
    ':is(p, span).foo',
    ':not(p.foo)',
    ':is(article > p)',
    ':not(article > p)',
    ':not(:not(p))',
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
$p1 = $doc->querySelector('#p1');
$pf = $doc->querySelector('#pf');
$art = $doc->querySelector('#art');
try {
    echo 'matches_p1_not_span=', $p1->matches(':not(span)') ? 'yes' : 'no', "\n";
    echo 'matches_p1_not_p=', $p1->matches(':not(p)') ? 'yes' : 'no', "\n";
    echo 'matches_pf_not_foo=', $pf->matches(':not(.foo)') ? 'yes' : 'no', "\n";
    echo 'matches_art_is=', $art->matches(':is(article, main)') ? 'yes' : 'no', "\n";
    echo 'matches_p1_is_p=', $p1->matches(':is(p, span)') ? 'yes' : 'no', "\n";
    echo 'matches_p1_is_child=', $p1->matches(':is(article > p)') ? 'yes' : 'no', "\n";
    $p2 = $doc->querySelector('#p2');
    echo 'matches_p2_is_art_child=', $p2->matches(':is(article > p)') ? 'yes' : 'no', "\n";
    $closest = $p1->closest(':is(article, main)');
    echo 'closest=', $closest !== null ? $closest->getAttribute('id') : 'null', "\n";
} catch (DOMException $ex) {
    echo 'matches=EX:', $ex->getMessage(), "\n";
}
foreach ([':not()', ':is()', ':where()', ':not', ':is', 'p:not(', ':not(())', ':unknown(p)'] as $bad) {
    try {
        $el = $doc->querySelector($bad);
        echo "bad[$bad]=", $el === null ? 'null' : 'hit', "\n";
    } catch (DOMException $ex) {
        echo "bad[$bad]=", $ex->getMessage(), "\n";
    }
}
