--TEST--
Dom\ ParentNode::$children — undefined on PROFILE=8.4 (Zend 8.4.23; #21559, re-#21033)
--SKIPIF--
<?php
if (!class_exists('Dom\\HTMLDocument')) {
    die('skip Dom\\HTMLDocument requires PHP_COMPILER_PROFILE=8.4 (#21559)');
}
if (\PHPCompiler\CompilerVersion::supportsDomParentNodeChildren()) {
    die('skip $children advertised on PROFILE=8.5+ — see dom_parentnode_children_htmlcollection_85.phpt');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
set_error_handler(function (int $n, string $m): bool {
    if (E_WARNING === $n || 2 === $n) {
        echo "ERR\n";
        return true;
    }
    return false;
});
$doc = Dom\HTMLDocument::createFromString(
    '<!doctype html><html><body><div id="a"><p>1</p><!--c--><span>2</span>text</div></body></html>',
    LIBXML_NOERROR
);
$el = $doc->getElementById('a');
echo 'isset=', isset($el->children) ? 'yes' : 'no', "\n";
$c = $el->children;
echo 'type=', get_debug_type($c), "\n";
echo 'count=', $el->childElementCount, "\n";
echo 'first=', null !== $el->firstElementChild ? $el->firstElementChild->tagName : 'null', "\n";
echo 'last=', null !== $el->lastElementChild ? $el->lastElementChild->tagName : 'null', "\n";

echo 'doc_isset=', isset($doc->children) ? 'yes' : 'no', "\n";
$dc = $doc->children;
echo 'doc_type=', get_debug_type($dc), "\n";

$frag = $doc->createDocumentFragment();
$frag->appendChild($doc->createElement('x'));
echo 'frag_isset=', isset($frag->children) ? 'yes' : 'no', "\n";
$fc = $frag->children;
echo 'frag_type=', get_debug_type($fc), "\n";
?>
--EXPECT--
isset=no
ERR
type=null
count=2
first=P
last=SPAN
doc_isset=no
ERR
doc_type=null
frag_isset=no
ERR
frag_type=null
