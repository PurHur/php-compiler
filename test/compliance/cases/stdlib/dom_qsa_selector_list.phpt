--TEST--
stdlib Dom Element CSS selector lists comma (#20689, ext/dom/parentnode.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = Dom\HTMLDocument::createFromString(
    '<html><body><div id="a" class="x"><span id="b"></span><p id="c"></p></div></body></html>'
);
$body = $doc->body;
$list = $body->querySelectorAll('span, p');
echo 'body_qsa=', $list->length, "\n";
$qs = $body->querySelector('p, span');
echo 'body_qs=', ($qs !== null ? $qs->id : 'null'), "\n";
echo 'matches=', $doc->getElementById('a')->matches('.x, .y') ? 'yes' : 'no', "\n";
$el = $doc->getElementById('b');
$div = $el->closest('div, p');
echo 'closest_id=', ($div !== null ? $div->id : 'null'), "\n";
?>
--EXPECT--
body_qsa=2
body_qs=b
matches=yes
closest_id=a
