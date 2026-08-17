--TEST--
DOMXPath parent/ancestor/following-sibling/preceding-sibling/self/child:: / .. (#31773)
--FILE--
<?php
function names($n): string
{
    if (false === $n) {
        return 'false';
    }
    $out = [];
    foreach ($n as $node) {
        $out[] = $node->nodeName;
    }

    return $n->length.'['.implode(',', $out).']';
}

$d = new DOMDocument();
$d->loadXML('<r><a id="1">one</a><a id="2">two</a><b>three</b></r>');
$xp = new DOMXPath($d);
echo 'following=', names($xp->query('//a[1]/following-sibling::*')), "\n";
echo 'following_b=', names($xp->query('//a[1]/following-sibling::b')), "\n";
echo 'preceding=', names($xp->query('//b/preceding-sibling::*')), "\n";
echo 'ancestor=', names($xp->query('//b/ancestor::*')), "\n";
echo 'ancestor_self=', names($xp->query('//b/ancestor-or-self::*')), "\n";
echo 'parent=', names($xp->query('//a[1]/parent::*')), "\n";
echo 'dotdot=', names($xp->query('//a[1]/..')), "\n";
echo 'self=', names($xp->query('//a[1]/self::a')), "\n";
echo 'child_axis=', names($xp->query('/r/child::*')), "\n";
echo 'following_all=', names($xp->query('//a[1]/following::*')), "\n";
echo 'root_parent=', names($xp->query('..')), "\n";
echo 'parent_star=', names($xp->query('parent::*')), "\n";
?>
--EXPECT--
following=2[a,b]
following_b=1[b]
preceding=2[a,a]
ancestor=1[r]
ancestor_self=2[r,b]
parent=1[r]
dotdot=1[r]
self=1[a]
child_axis=3[a,a,b]
following_all=2[a,b]
root_parent=1[#document]
parent_star=0[]
