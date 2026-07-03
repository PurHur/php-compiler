--TEST--
DOM DOMDocument::$documentElement is read-only (ext/dom/php_dom.c; #15550)
--FILE--
<?php
declare(strict_types=1);
$doc = new DOMDocument();
try {
    $doc->documentElement = $doc->createElement('root');
    echo "assigned\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
$root = $doc->createElement('root');
$doc->appendChild($root);
echo $doc->documentElement->nodeName, "\n";
?>
--EXPECT--
Cannot write read-only property DOMDocument::$documentElement
root
