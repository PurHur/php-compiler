--TEST--
stdlib DOMNode::$isConnected (#19653, ext/dom/node.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsDomNodeIsConnected()) {
    die('skip DOMNode::$isConnected not advertised on PHP 8.2 reference profile (#19653, ext/dom/node.c)');
}
?>
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><a/></root>');
$a = $doc->documentElement->firstChild;
echo ($a->isConnected === true) ? "connected\n" : "connected_fail\n";
$doc->documentElement->removeChild($a);
echo ($a->isConnected === false) ? "detached\n" : "detached_fail\n";
echo ($doc->isConnected === true) ? "doc_ok\n" : "doc_fail\n";
$orphan = $doc->createElement('orphan');
echo ($orphan->isConnected === false) ? "orphan_ok\n" : "orphan_fail\n";
?>
--EXPECT--
connected
detached
doc_ok
orphan_ok
