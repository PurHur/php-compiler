--TEST--
AOT Dom\import_simplexml after simplexml_load_string (#34481)
--FILE--
<?php
declare(strict_types=1);
$x = simplexml_load_string('<root id="1"><item>a</item></root>');
$d = Dom\import_simplexml($x);
echo get_class($d), "\n";
echo $d->nodeName, ':', $d->getAttribute('id'), "\n";
echo "DONE\n";
--EXPECT--
Dom\Element
root:1
DONE
