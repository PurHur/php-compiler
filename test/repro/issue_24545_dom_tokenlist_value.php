<?php
// Repro #24545 — Dom\TokenList::$value write + __toString
$html = Dom\HTMLDocument::createFromString(
    '<!DOCTYPE html><html><body><div class="a b" id="d"></div></body></html>'
);
$cl = $html->getElementById('d')->classList;
echo 'class=', get_class($cl), "\n";
echo 'before=', $cl->value, "\n";
$cl->value = 'c d';
echo 'after=', $cl->value, "\n";
echo 'attr=', $html->getElementById('d')->getAttribute('class'), "\n";
echo 'has_toString=', method_exists($cl, '__toString') ? '1' : '0', "\n";
echo 'cast=', (string) $cl, "\n";
