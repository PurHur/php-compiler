--TEST--
AOT: foreach over loadXML-seeded childNodes (#33082)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/><b/><c/></r>');
$out = [];
foreach ($d->documentElement->childNodes as $n) {
    $out[] = $n->nodeName;
}
echo implode(',', $out), "\n";
?>
--EXPECT--
a,b,c
