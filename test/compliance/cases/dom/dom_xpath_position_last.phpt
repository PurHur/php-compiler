--TEST--
dom DOMXPath::query position()/last() predicates (#25083, ext/dom/xpath.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r><a>1</a><a>2</a><a>3</a></r>');
$xp = new DOMXPath($doc);
foreach ([
    '//a[2]',
    '//a[position()=2]',
    '//a[position()=1]',
    '//a[last()]',
    '//a[position()=last()]',
    '//a[position()>1]',
] as $q) {
    $n = $xp->query($q);
    $vals = [];
    if (false !== $n) {
        foreach ($n as $node) {
            $vals[] = $node->textContent;
        }
    }
    echo $q, "\t", false === $n ? 'false' : ('len='.$n->length.'['.implode(',', $vals).']'), "\n";
}
--EXPECT--
//a[2]	len=1[2]
//a[position()=2]	len=1[2]
//a[position()=1]	len=1[1]
//a[last()]	len=1[3]
//a[position()=last()]	len=1[3]
//a[position()>1]	len=2[2,3]
