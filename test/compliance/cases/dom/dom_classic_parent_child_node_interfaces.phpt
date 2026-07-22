--TEST--
Classic DOMParentNode / DOMChildNode — instanceof + typehints (#22389)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a/></r>');
$el = $d->documentElement;
$child = $el->firstChild;
$frag = $d->createDocumentFragment();
$text = $d->createTextNode('t');
$comment = $d->createComment('c');

echo 'doc_pn=', ($d instanceof DOMParentNode) ? '1' : '0', "\n";
echo 'doc_cn=', ($d instanceof DOMChildNode) ? '1' : '0', "\n";
echo 'el_pn=', ($el instanceof DOMParentNode) ? '1' : '0', "\n";
echo 'el_cn=', ($el instanceof DOMChildNode) ? '1' : '0', "\n";
echo 'child_cn=', ($child instanceof DOMChildNode) ? '1' : '0', "\n";
echo 'frag_pn=', ($frag instanceof DOMParentNode) ? '1' : '0', "\n";
echo 'frag_cn=', ($frag instanceof DOMChildNode) ? '1' : '0', "\n";
echo 'text_cn=', ($text instanceof DOMChildNode) ? '1' : '0', "\n";
echo 'text_pn=', ($text instanceof DOMParentNode) ? '1' : '0', "\n";
echo 'comment_cn=', ($comment instanceof DOMChildNode) ? '1' : '0', "\n";

$elImpl = array_keys(class_implements(DOMElement::class));
sort($elImpl);
echo 'el_implements=', implode(',', $elImpl), "\n";
$docImpl = array_keys(class_implements(DOMDocument::class));
sort($docImpl);
echo 'doc_implements=', implode(',', $docImpl), "\n";
$cdImpl = array_keys(class_implements(DOMCharacterData::class));
sort($cdImpl);
echo 'cd_implements=', implode(',', $cdImpl), "\n";

try {
    (function (DOMParentNode $n) {
        return 'ok';
    })($el);
    echo "typehint=ok\n";
} catch (Throwable $e) {
    echo 'typehint=', get_class($e), "\n";
}
?>
--EXPECT--
doc_pn=1
doc_cn=0
el_pn=1
el_cn=1
child_cn=1
frag_pn=1
frag_cn=0
text_cn=1
text_pn=0
comment_cn=1
el_implements=DOMChildNode,DOMParentNode
doc_implements=DOMParentNode
cd_implements=DOMChildNode
typehint=ok
