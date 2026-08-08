--TEST--
AOT DOMXPath::registerNamespace empty prefix + unprefixed //tag vs default NS (#29135, #29139)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r xmlns="urn:def"><c/></r>');
$xp = new DOMXPath($d);
echo 'empty=', $xp->registerNamespace('', 'urn:x') ? 'true' : 'false', "\n";
echo 'ok=', $xp->registerNamespace('p', 'urn:x') ? 'true' : 'false', "\n";
echo 'bare=', $xp->query('//c')->length, "\n";
$xp->registerNamespace('d', 'urn:def');
echo 'prefixed=', $xp->query('//d:c')->length, "\n";
?>
--EXPECT--
empty=false
ok=true
bare=0
prefixed=1
