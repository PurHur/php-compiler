--TEST--
stdlib DOMNode::isEqualNode() structural equality (#15195, #24462, ext/dom/node.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsDomNodeIsEqualNode()) {
    die('skip DOMNode::isEqualNode() not advertised on PHP 8.2 reference profile (#15195, ext/dom/node.c)');
}
?>
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><a id="1"/></root>');
$a = $doc->documentElement->firstChild;
$b = $a->cloneNode(true);
echo (int) $a->isEqualNode($a), "\n";
echo (int) $a->isEqualNode($b), "\n";
echo (int) $a->isSameNode($b), "\n";
$doc2 = new DOMDocument();
$doc2->loadXML('<root><a id="2"/></root>');
$d = $doc2->documentElement->firstChild;
echo (int) $a->isEqualNode($d), "\n";
// php-src stub ?DOMNode — null → false (#24462)
echo (int) $a->isEqualNode(null), "\n";
?>
--EXPECT--
1
1
0
0
0
