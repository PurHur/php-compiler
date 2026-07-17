<?php
/** Repro #20280 — DOMXPath::evaluate comparisons, arithmetic, not(), name(). */
$doc = new DOMDocument();
$doc->loadXML('<r><a/><a/><b id="x">hi</b></r>');
$xp = new DOMXPath($doc);
$cases = [
    'count(//a) > 1',
    'count(//a) = 2',
    '2 > 1',
    '1+1',
    'count(//a) + 1',
    'not(//c)',
    'name(//b)',
];
foreach ($cases as $e) {
    $r = $xp->evaluate($e);
    if (is_bool($r)) {
        echo $e.' => '.($r ? 'true' : 'false')."\n";
    } elseif (is_float($r) || is_int($r)) {
        echo $e.' => '.var_export((float) $r, true)."\n";
    } else {
        echo $e.' => '.var_export($r, true)."\n";
    }
}
