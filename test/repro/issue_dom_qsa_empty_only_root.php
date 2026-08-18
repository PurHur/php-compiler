<?php
// Dom ParentNode CSS :empty / :only-child / :only-of-type / :root
// PROFILE=8.4 living Dom (php-src ext/dom/parentnode.c / lexbor).
$doc = Dom\XMLDocument::createFromString(
    '<container>'
    .'<g1 id="g1"><p id="lonely">Lonely</p></g1>'
    .'<g2 id="g2"><p id="a">A</p><p id="b">B</p></g2>'
    .'<g3 id="g3">x<p id="textsib">T</p></g3>'
    .'<g4 id="g4"><p id="mixp">P</p><span id="mixs">S</span></g4>'
    .'<e id="e0"/><e id="e1"></e><e id="e2"> </e>'
    .'<e id="e3"><!--c--></e><e id="e4"><?pi x?></e>'
    .'<e id="e5"><![CDATA[x]]></e><e id="e6"><i id="nested"/></e>'
    .'</container>'
);
foreach ([
    ':root',
    'container:root',
    'p:root',
    'p:only-child',
    'p:only-of-type',
    'span:only-of-type',
    'e:empty',
    'g3 p:only-child',
    'g3 p:first-child',
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
$lonely = $doc->querySelector('#lonely');
$a = $doc->querySelector('#a');
$textsib = $doc->querySelector('#textsib');
$mixp = $doc->querySelector('#mixp');
$e0 = $doc->querySelector('#e0');
$e2 = $doc->querySelector('#e2');
$e3 = $doc->querySelector('#e3');
$e5 = $doc->querySelector('#e5');
$e6 = $doc->querySelector('#e6');
$nested = $doc->querySelector('#nested');
echo 'matches_lonely_only=', $lonely->matches(':only-child') ? 'yes' : 'no', "\n";
echo 'matches_a_only=', $a->matches(':only-child') ? 'yes' : 'no', "\n";
echo 'matches_textsib_only=', $textsib->matches(':only-child') ? 'yes' : 'no', "\n";
echo 'matches_mixp_only_type=', $mixp->matches(':only-of-type') ? 'yes' : 'no', "\n";
echo 'matches_e0_empty=', $e0->matches(':empty') ? 'yes' : 'no', "\n";
echo 'matches_e2_empty=', $e2->matches(':empty') ? 'yes' : 'no', "\n";
echo 'matches_e3_empty=', $e3->matches(':empty') ? 'yes' : 'no', "\n";
echo 'matches_e5_empty=', $e5->matches(':empty') ? 'yes' : 'no', "\n";
echo 'matches_e6_empty=', $e6->matches(':empty') ? 'yes' : 'no', "\n";
echo 'matches_container_root=', $doc->documentElement->matches(':root') ? 'yes' : 'no', "\n";
echo 'matches_g1_root=', $doc->querySelector('#g1')->matches(':root') ? 'yes' : 'no', "\n";
$closest = $nested->closest(':root, e:empty');
echo 'closest=', $closest !== null
    ? ($closest->hasAttribute('id') && $closest->getAttribute('id') !== '' ? $closest->getAttribute('id') : $closest->nodeName)
    : 'null', "\n";

$frag = $doc->createDocumentFragment();
$frag->appendXML('<div id="froot"><p id="fp">x</p></div>');
try {
    $fr = $frag->querySelector(':root');
    echo 'frag_root=', $fr !== null ? $fr->getAttribute('id') : 'null', "\n";
    echo 'frag_p_root=', $frag->querySelector('p:root') !== null ? 'yes' : 'no', "\n";
} catch (DOMException $ex) {
    echo 'frag_root=EX:', $ex->getMessage(), "\n";
}
$loose = $doc->createElement('foo');
try {
    $lr = $loose->querySelector(':root');
    echo 'loose_qsa=', $lr !== null ? $lr->nodeName : 'null', "\n";
    echo 'loose_matches=', $loose->matches(':root') ? 'yes' : 'no', "\n";
    echo 'loose_empty=', $loose->matches(':empty') ? 'yes' : 'no', "\n";
} catch (DOMException $ex) {
    echo 'loose=EX:', $ex->getMessage(), "\n";
}
foreach ([':empty()', ':only-child()', ':root()', 'p:blank'] as $bad) {
    try {
        $doc->querySelector($bad);
        echo "bad[$bad]=ok\n";
    } catch (DOMException $ex) {
        echo "bad[$bad]=", $ex->getMessage(), "\n";
    }
}
