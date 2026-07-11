--TEST--
stdlib DOMNode::contains() descendant check (#14447, ext/dom/node.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsDomNodeContains()) {
    die('skip DOMNode::contains() not advertised on PHP 8.2 reference profile (#14535, ext/dom/node.c)');
}
?>
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root><parent><child/></parent><sibling/></root>');
$root = $doc->documentElement;
$parent = $root->firstChild;
$child = $parent->firstChild;
$sibling = $root->lastChild;
echo (int) $root->contains($child), "\n";
echo (int) $parent->contains($child), "\n";
echo (int) $child->contains($root), "\n";
echo (int) $root->contains($sibling), "\n";
echo (int) $root->contains($root), "\n";
echo (int) $root->contains(null), "\n";
?>
--EXPECT--
1
1
0
1
1
0
