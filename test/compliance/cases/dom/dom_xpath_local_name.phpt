--TEST--
dom DOMXPath local-name()/name() + unprefixed //x + //* includes document element (#21125, ext/dom/xpath.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<?xml version="1.0"?><r xmlns:a="urn:a"><a:x>hit</a:x><x>miss</x><y>keep</y></r>');
$xp = new DOMXPath($d);
foreach ([
    '//*',
    '//x',
    '//*[local-name()="x"]',
    '//*[local-name()="y"]',
    '//*[name()="x"]',
    '//*[name()="a:x"]',
] as $q) {
    $n = $xp->query($q);
    echo $q, ' => ', ($n === false ? 'false' : $n->length), "\n";
}
echo 'rel=', $xp->query('.//*', $d->documentElement)->length, "\n";
--EXPECT--
//* => 4
//x => 1
//*[local-name()="x"] => 2
//*[local-name()="y"] => 1
//*[name()="x"] => 1
//*[name()="a:x"] => 1
rel=3
