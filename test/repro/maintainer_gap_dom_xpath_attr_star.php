<?php
// Repro #32003 — DOMXPath //@* / attribute::* Attr node-set (xpath.c)
$d = new DOMDocument();
$d->loadXML('<r><a id="x" class="c">1</a><b y="2"/></r>');
$xp = new DOMXPath($d);

function names($n): string
{
    if (false === $n) {
        return 'false';
    }
    $out = [];
    for ($i = 0; $i < $n->length; $i++) {
        $item = $n->item($i);
        $out[] = $item ? $item->nodeName : '?';
    }

    return $n->length.'['.implode(',', $out).']';
}

echo 'star=', names($xp->query('//@*')), "\n";
echo 'a_star=', names($xp->query('//a/@*')), "\n";
echo 'axis_star=', names($xp->query('//attribute::*')), "\n";
echo 'a_axis_star=', names($xp->query('//a/attribute::*')), "\n";
echo 'a_axis_id=', names($xp->query('//a/attribute::id')), "\n";
echo 'named=', names($xp->query('//@id')), "\n";
$a = $xp->query('//a')->item(0);
echo 'rel_star=', names($xp->query('@*', $a)), "\n";
echo 'count=', $xp->evaluate('count(//@*)'), "\n";
echo 'string=', var_export($xp->evaluate('string(//@*)'), true), "\n";
