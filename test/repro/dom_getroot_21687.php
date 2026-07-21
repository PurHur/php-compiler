<?php
$doc = new DOMDocument();
$root = $doc->createElement('root');
$child = $doc->createElement('child');
$leaf = $doc->createElement('leaf');
echo ($leaf->getRootNode() === $doc) ? "leaf_doc\n" : "leaf_other\n";
echo ($root->getRootNode() === $doc) ? "elem_doc\n" : "elem_other\n";
echo ($child->getRootNode() === $doc) ? "child_doc\n" : "child_other\n";
