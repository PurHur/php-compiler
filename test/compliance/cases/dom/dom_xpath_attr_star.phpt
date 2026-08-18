--TEST--
DOMXPath //@* / attribute::* attribute axis (#32003, ext/dom/xpath.c)
--FILE--
<?php
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

$d = new DOMDocument();
$d->loadXML('<r><a id="x" class="c">1</a><b y="2"/></r>');
$xp = new DOMXPath($d);
echo 'star=', names($xp->query('//@*')), "\n";
echo 'a_star=', names($xp->query('//a/@*')), "\n";
echo 'axis_star=', names($xp->query('//attribute::*')), "\n";
echo 'a_axis_star=', names($xp->query('//a/attribute::*')), "\n";
echo 'a_axis_id=', names($xp->query('//a/attribute::id')), "\n";
echo 'named=', names($xp->query('//@id')), "\n";
$a = $xp->query('//a')->item(0);
echo 'rel_star=', names($xp->query('@*', $a)), "\n";
echo 'count=', var_export($xp->evaluate('count(//@*)'), true), "\n";
echo 'string=', var_export($xp->evaluate('string(//@*)'), true), "\n";

$ns = new DOMDocument();
$ns->loadXML('<r xmlns:p="urn:p" id="x"><p:x p:a="1" b="2"/></r>');
$xp2 = new DOMXPath($ns);
$xp2->registerNamespace('p', 'urn:p');
echo 'ns_star=', names($xp2->query('//@*')), "\n";
echo 'ns_pa=', names($xp2->query('//@p:a')), "\n";
?>
--EXPECT--
star=3[id,class,y]
a_star=2[id,class]
axis_star=3[id,class,y]
a_axis_star=2[id,class]
a_axis_id=1[id]
named=1[id]
rel_star=2[id,class]
count=3.0
string='x'
ns_star=3[id,p:a,b]
ns_pa=1[p:a]
