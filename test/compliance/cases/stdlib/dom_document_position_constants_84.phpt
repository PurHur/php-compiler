--TEST--
DOMNode / Dom\Node DOCUMENT_POSITION_* constants under PROFILE≥8.4 (#26060)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsDomNodeCompareDocumentPosition()) {
    die('skip DOCUMENT_POSITION_* require PHP_COMPILER_PROFILE=8.4 (#26060)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo DOMNode::DOCUMENT_POSITION_DISCONNECTED, ',';
echo DOMNode::DOCUMENT_POSITION_PRECEDING, ',';
echo DOMNode::DOCUMENT_POSITION_FOLLOWING, ',';
echo DOMNode::DOCUMENT_POSITION_CONTAINS, ',';
echo DOMNode::DOCUMENT_POSITION_CONTAINED_BY, ',';
echo DOMNode::DOCUMENT_POSITION_IMPLEMENTATION_SPECIFIC, "\n";

$r = new ReflectionClass(DOMNode::class);
$keys = array_keys($r->getConstants());
sort($keys);
echo implode(',', $keys), "\n";

echo Dom\Node::DOCUMENT_POSITION_CONTAINED_BY, "\n";
$r2 = new ReflectionClass(Dom\Node::class);
echo count($r2->getConstants()), "\n";
?>
--EXPECT--
1,2,4,8,16,32
DOCUMENT_POSITION_CONTAINED_BY,DOCUMENT_POSITION_CONTAINS,DOCUMENT_POSITION_DISCONNECTED,DOCUMENT_POSITION_FOLLOWING,DOCUMENT_POSITION_IMPLEMENTATION_SPECIFIC,DOCUMENT_POSITION_PRECEDING
16
6
