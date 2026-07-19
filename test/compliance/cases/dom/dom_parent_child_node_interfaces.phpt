--TEST--
Dom\ParentNode / Dom\ChildNode interfaces — instanceof + type hints (#20961)
--SKIPIF--
<?php
if (!interface_exists('Dom\\ParentNode') && !class_exists('Dom\\HTMLDocument')) {
    die('skip Dom\\ living interfaces require PHP_COMPILER_PROFILE=8.4 (#20961)');
}
if (!class_exists('Dom\\HTMLDocument')) {
    die('skip Dom\\HTMLDocument requires PHP_COMPILER_PROFILE=8.4 (#20961)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo 'ParentNode=', interface_exists('Dom\\ParentNode') ? 'yes' : 'no', "\n";
echo 'ChildNode=', interface_exists('Dom\\ChildNode') ? 'yes' : 'no', "\n";

$doc = Dom\HTMLDocument::createEmpty();
$el = $doc->createElement('p');
$text = $doc->createTextNode('hi');
$frag = $doc->createDocumentFragment();
$comment = $doc->createComment('c');

echo 'doc_pn=', ($doc instanceof Dom\ParentNode) ? 'yes' : 'no', "\n";
echo 'doc_cn=', ($doc instanceof Dom\ChildNode) ? 'yes' : 'no', "\n";
echo 'el_pn=', ($el instanceof Dom\ParentNode) ? 'yes' : 'no', "\n";
echo 'el_cn=', ($el instanceof Dom\ChildNode) ? 'yes' : 'no', "\n";
echo 'text_cn=', ($text instanceof Dom\ChildNode) ? 'yes' : 'no', "\n";
echo 'comment_cn=', ($comment instanceof Dom\ChildNode) ? 'yes' : 'no', "\n";
echo 'frag_pn=', ($frag instanceof Dom\ParentNode) ? 'yes' : 'no', "\n";
echo 'attr_pn=', ($doc->createAttribute('id') instanceof Dom\ParentNode) ? 'yes' : 'no', "\n";

function takes_parent(Dom\ParentNode $n): string
{
    return get_class($n);
}
function takes_child(Dom\ChildNode $n): string
{
    return get_class($n);
}
echo 'hint_doc=', takes_parent($doc), "\n";
echo 'hint_el=', takes_child($el), "\n";
echo 'methods_pn=', implode(',', get_class_methods('Dom\\ParentNode') ?: []), "\n";
echo 'methods_cn=', implode(',', get_class_methods('Dom\\ChildNode') ?: []), "\n";
?>
--EXPECT--
ParentNode=yes
ChildNode=yes
doc_pn=yes
doc_cn=no
el_pn=yes
el_cn=yes
text_cn=yes
comment_cn=yes
frag_pn=yes
attr_pn=no
hint_doc=Dom\HTMLDocument
hint_el=Dom\HTMLElement
methods_pn=append,prepend,replaceChildren,querySelector,querySelectorAll
methods_cn=remove,before,after,replaceWith
