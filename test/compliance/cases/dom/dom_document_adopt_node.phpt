--TEST--
DOMDocument::adoptNode() registered — Zend 8.2 stub throws Not yet implemented (#17494, ext/dom/document.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><a/></root>');
$node = $doc->documentElement->firstChild;
echo (int) method_exists($doc, 'adoptNode'), "\n";
try {
    $doc->adoptNode($node);
    echo "no_throw\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
1
Not yet implemented
