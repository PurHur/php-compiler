--TEST--
SimpleXML: simplexml_import_dom(DOMDocument) uses documentElement (#19552, ext/simplexml/simplexml.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a>1</a></r>');
$x = simplexml_import_dom($d);
echo get_class($x), ' a=', (string) $x->a, "\n";
--EXPECT--
SimpleXMLElement a=1
