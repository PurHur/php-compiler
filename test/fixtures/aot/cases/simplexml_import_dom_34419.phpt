--TEST--
AOT simplexml_import_dom after DOMDocument::loadXML (#34419)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r a="1"><c/></r>');
$x = simplexml_import_dom($d);
echo $x->getName(), ':', (string) $x['a'], "\n";
echo "DONE\n";
--EXPECT--
r:1
DONE
