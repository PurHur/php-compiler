--TEST--
stdlib DOMNode::replaceChildren() living-standard child replacement (#16822, ext/dom/parentnode.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsDomNodeReplaceChildren()) {
    die('skip DOMNode::replaceChildren() not advertised on PHP 8.2 reference profile (#17119, ext/dom/parentnode.c)');
}
?>
--FILE--
<?php
$doc = new DOMDocument();
$parent = $doc->createElement('parent');
$doc->appendChild($parent);
$parent->appendChild($doc->createElement('old'));
$new = $doc->createElement('new');
$parent->replaceChildren($new);
echo $parent->childNodes->length, "\n";
echo $parent->firstChild->nodeName, "\n";
$parent->replaceChildren();
echo $parent->childNodes->length, "\n";
?>
--EXPECT--
1
new
0
