--TEST--
DOMXPath //*[last()] / //*[position()=1] per-parent child axis (#31923, ext/dom/xpath.c)
--FILE--
<?php
function names($n): string
{
    if (false === $n) {
        return 'false';
    }
    $out = [];
    for ($i = 0; $i < $n->length; $i++) {
        $out[] = $n->item($i)->nodeName;
    }

    return $n->length.'['.implode(',', $out).']';
}

function texts($n): string
{
    if (false === $n) {
        return 'false';
    }
    $out = [];
    for ($i = 0; $i < $n->length; $i++) {
        $out[] = $n->item($i)->textContent;
    }

    return $n->length.'['.implode(',', $out).']';
}

$d = new DOMDocument();
$d->loadXML('<r><a id="1">one</a><a id="2">two</a><b>three</b></r>');
$xp = new DOMXPath($d);
echo 'star_last=', names($xp->query('//*[last()]')), "\n";
echo 'star_pos1=', names($xp->query('//*[position()=1]')), "\n";
echo 'a_last=', names($xp->query('//a[last()]')), "\n";

$nested = new DOMDocument();
$nested->loadXML('<r><x><a>1</a><a>2</a></x><a>3</a></r>');
$xp2 = new DOMXPath($nested);
echo 'nested_a_last=', texts($xp2->query('//a[last()]')), "\n";
echo 'nested_a_pos1=', texts($xp2->query('//a[position()=1]')), "\n";
?>
--EXPECT--
star_last=2[r,b]
star_pos1=2[r,a]
a_last=1[a]
nested_a_last=2[2,3]
nested_a_pos1=2[1,3]
