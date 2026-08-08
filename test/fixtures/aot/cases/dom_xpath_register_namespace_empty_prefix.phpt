--TEST--
AOT DOMXPath::registerNamespace empty prefix returns false (#29135)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r/>');
$xp = new DOMXPath($d);
echo 'empty=', $xp->registerNamespace('', 'urn:x') ? 'true' : 'false', "\n";
echo 'ok=', $xp->registerNamespace('p', 'urn:x') ? 'true' : 'false', "\n";
?>
--EXPECT--
empty=false
ok=true
