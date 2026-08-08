--TEST--
DOMXPath::registerNamespace empty prefix returns false (#29135, ext/dom/xpath.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r xmlns="urn:def"><c/></r>');
$xp = new DOMXPath($d);
echo 'empty=', $xp->registerNamespace('', 'urn:x') ? 'true' : 'false', "\n";
echo 'empty_both=', $xp->registerNamespace('', '') ? 'true' : 'false', "\n";
echo 'ok=', $xp->registerNamespace('p', 'urn:x') ? 'true' : 'false', "\n";
echo 'empty_uri=', $xp->registerNamespace('q', '') ? 'true' : 'false', "\n";
echo 'bare=', $xp->query('//c')->length, "\n";
$xp->registerNamespace('d', 'urn:def');
echo 'prefixed=', $xp->query('//d:c')->length, "\n";
?>
--EXPECT--
empty=false
empty_both=false
ok=true
empty_uri=true
bare=0
prefixed=1
