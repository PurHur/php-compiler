--TEST--
Dom\XPath unprefixed //div misses XHTML-namespaced HTML — need prefix (#26007)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$d = Dom\HTMLDocument::createFromString(
    '<!DOCTYPE html><html><body><div id="x">hi</div></body></html>'
);
$xp = new Dom\XPath($d);
echo 'bare=', $xp->query('//div')->length, "\n";
$xp->registerNamespace('h', 'http://www.w3.org/1999/xhtml');
echo 'pref=', $xp->query('//h:div')->length, "\n";
echo 'bytag=', $d->getElementsByTagName('div')->length, "\n";
--EXPECT--
bare=0
pref=1
bytag=1
