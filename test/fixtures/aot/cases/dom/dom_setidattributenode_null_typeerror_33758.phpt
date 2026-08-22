--TEST--
AOT: DOMElement::setIdAttributeNode(null) TypeError (#33758, ext/dom/element.c)
--FILE--
<?php
error_reporting(E_ALL);
$d = new DOMDocument();
$e = $d->createElement('e');
$n = null;
try {
    $e->setIdAttributeNode($n, true);
    echo "var=fail\n";
} catch (Throwable $ex) {
    echo 'var=', get_class($ex), ':', $ex->getMessage(), "\n";
}
try {
    $e->setIdAttributeNode(null, true);
    echo "lit=fail\n";
} catch (Throwable $ex) {
    echo 'lit=', get_class($ex), ':', $ex->getMessage(), "\n";
}
?>
--EXPECT--
var=TypeError:DOMElement::setIdAttributeNode(): Argument #1 ($attr) must be of type DOMAttr, null given
lit=TypeError:DOMElement::setIdAttributeNode(): Argument #1 ($attr) must be of type DOMAttr, null given
