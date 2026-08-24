--TEST--
AOT dom_import_simplexml after simplexml_load_string (#34413)
--FILE--
<?php
$x = simplexml_load_string('<r a="1"><c/></r>');
$d = dom_import_simplexml($x);
echo $d->nodeName, ':', $d->getAttribute('a'), "\n";
echo "DONE\n";
--EXPECT--
r:1
DONE
