--TEST--
DOM ChildNode / ParentNode method placement matches php_dom.stub.php (#23155)
--FILE--
<?php
foreach (['DOMDocument', 'DOMElement', 'DOMNode', 'DOMDocumentFragment', 'DOMAttr', 'DOMComment', 'DOMText'] as $c) {
    $ms = [];
    foreach (['after', 'before', 'remove', 'replaceWith', 'append', 'prepend'] as $m) {
        if (method_exists($c, $m)) {
            $ms[] = $m;
        }
    }
    echo $c, "\t", implode(',', $ms), "\n";
}

$doc = new DOMDocument();
$doc->loadXML('<r/>');
try {
    $doc->after('x');
    echo "doc_after=ok\n";
} catch (Error $e) {
    echo 'doc_after=', get_class($e), "\n";
}
$attr = $doc->createAttribute('id');
try {
    $attr->remove();
    echo "attr_remove=ok\n";
} catch (Error $e) {
    echo 'attr_remove=', get_class($e), "\n";
}
$el = $doc->documentElement;
$el->append($doc->createElement('c'));
echo 'el_append=', $el->childNodes->length, "\n";
$el->firstChild->after($doc->createElement('d'));
echo 'child_after=', $el->childNodes->length, "\n";
?>
--EXPECT--
DOMDocument	append,prepend
DOMElement	after,before,remove,replaceWith,append,prepend
DOMNode	
DOMDocumentFragment	append,prepend
DOMAttr	
DOMComment	after,before,remove,replaceWith
DOMText	after,before,remove,replaceWith
doc_after=Error
attr_remove=Error
el_append=1
child_after=2
