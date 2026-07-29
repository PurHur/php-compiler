--TEST--
DOMDocument::adoptNode() Error Not yet implemented on reference 8.2 profile (#24995, ext/dom/document.c)
--FILE--
<?php
$a = new DOMDocument();
$a->loadXML('<r><x/></r>');
$b = new DOMDocument();
echo (int) method_exists($b, 'adoptNode'), "\n";
try {
    $n = $b->adoptNode($a->documentElement->firstChild);
    echo 'ok:', $n->nodeName, "\n";
} catch (Error $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
1
Error:Not yet implemented
