<?php
// #20961 — Dom\ParentNode / Dom\ChildNode interface registration (php-src php_dom.stub.php).
// Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_20961_dom_parent_child_node.php

echo 'ParentNode=', interface_exists('Dom\\ParentNode') ? 'yes' : 'no', "\n";
echo 'ChildNode=', interface_exists('Dom\\ChildNode') ? 'yes' : 'no', "\n";

$doc = Dom\HTMLDocument::createEmpty();
$el = $doc->createElement('p');
$text = $doc->createTextNode('hi');
$frag = $doc->createDocumentFragment();

echo 'doc ParentNode=', ($doc instanceof Dom\ParentNode) ? 'yes' : 'no', "\n";
echo 'doc ChildNode=', ($doc instanceof Dom\ChildNode) ? 'yes' : 'no', "\n";
echo 'el ParentNode=', ($el instanceof Dom\ParentNode) ? 'yes' : 'no', "\n";
echo 'el ChildNode=', ($el instanceof Dom\ChildNode) ? 'yes' : 'no', "\n";
echo 'text ChildNode=', ($text instanceof Dom\ChildNode) ? 'yes' : 'no', "\n";
echo 'frag ParentNode=', ($frag instanceof Dom\ParentNode) ? 'yes' : 'no', "\n";

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
