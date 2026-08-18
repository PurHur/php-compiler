--TEST--
stdlib Dom ParentNode CSS child/sibling combinators (#32061, ext/dom/parentnode.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = Dom\HTMLDocument::createFromString(
    '<html><body><div id="d"><p id="p">x</p><span id="s"><em id="e">y</em></span></div><p id="p2">z</p></body></html>'
);
$child = $doc->querySelector('div > p');
echo 'child=', $child !== null ? $child->id : 'null', "\n";
$compact = $doc->querySelector('div>p');
echo 'compact=', $compact !== null ? $compact->id : 'null', "\n";
$nested = $doc->querySelector('div > span > em');
echo 'nested=', $nested !== null ? $nested->id : 'null', "\n";
echo 'qsa_child=', $doc->querySelectorAll('div > p')->length, "\n";
$adj = $doc->querySelector('p + span');
echo 'adj=', $adj !== null ? $adj->id : 'null', "\n";
$gen = $doc->querySelector('div ~ p');
echo 'gen=', $gen !== null ? $gen->id : 'null', "\n";
$bodyP = $doc->querySelector('body > p');
echo 'body_p=', $bodyP !== null ? $bodyP->id : 'null', "\n";
echo 'desc=', $doc->querySelector('div p')->id, "\n";
$p = $doc->getElementById('p');
$e = $doc->getElementById('e');
$s = $doc->getElementById('s');
echo 'matches_child=', $p->matches('div > p') ? 'yes' : 'no', "\n";
echo 'matches_nested=', $e->matches('div > p') ? 'yes' : 'no', "\n";
echo 'matches_adj=', $s->matches('p + span') ? 'yes' : 'no', "\n";
$closest = $e->closest('div > span');
echo 'closest=', $closest !== null ? $closest->id : 'null', "\n";
$firstChild = $doc->querySelector('div > p:first-child');
echo 'first=', $firstChild !== null ? $firstChild->id : 'null', "\n";
foreach (['div >', '> p', 'div > > p', 'p++span'] as $bad) {
    try {
        $doc->querySelector($bad);
        echo "bad[$bad]=ok\n";
    } catch (DOMException $ex) {
        echo "bad[$bad]=", $ex->getMessage(), "\n";
    }
}
?>
--EXPECT--
child=p
compact=p
nested=e
qsa_child=1
adj=s
gen=p2
body_p=p2
desc=p
matches_child=yes
matches_nested=no
matches_adj=yes
closest=s
first=p
bad[div >]=SyntaxError
bad[> p]=SyntaxError
bad[div > > p]=SyntaxError
bad[p++span]=SyntaxError
