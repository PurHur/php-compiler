--TEST--
stdlib Dom\Element closest/matches/querySelector* on living HTML nodes (#20418, ext/dom)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = Dom\HTMLDocument::createFromString(
    '<html><body><div id="a" class="x"><span id="b"></span></div></body></html>'
);
$el = $doc->getElementById('b');
echo 'class=', $el ? get_class($el) : 'null', "\n";
foreach (['closest', 'matches', 'querySelector', 'querySelectorAll'] as $m) {
    echo $m, '=', ($el && method_exists($el, $m)) ? 'yes' : 'no', "\n";
}
$div = $el->closest('div');
echo 'closest_id=', ($div !== null ? $div->id : 'null'), "\n";
echo 'matches=', $doc->getElementById('a')->matches('.x') ? 'yes' : 'no', "\n";
$body = $doc->body;
$qs = $body->querySelector('#b');
echo 'body_qs=', ($qs !== null ? $qs->id : 'null'), "\n";
$list = $body->querySelectorAll('span');
echo 'body_qsa=', $list->length, "\n";
?>
--EXPECT--
class=Dom\HTMLElement
closest=yes
matches=yes
querySelector=yes
querySelectorAll=yes
closest_id=a
matches=yes
body_qs=b
body_qsa=1
