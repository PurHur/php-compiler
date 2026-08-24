--TEST--
AOT: simplexml_load_string + dom_import_simplexml nodeName/getAttribute (#34413)
--FILE--
<?php
declare(strict_types=1);
$x = simplexml_load_string('<r a="1"><c/></r>');
$el = dom_import_simplexml($x);
echo $el->nodeName, ':', $el->getAttribute('a'), "\n";
--EXPECT--
r:1
--EXPECT_EXIT--
0
