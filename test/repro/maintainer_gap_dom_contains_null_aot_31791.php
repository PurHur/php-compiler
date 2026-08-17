<?php
// #31791 — AOT DOMNode::contains(null) / variable null must return 0 (not segfault).
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
echo (int) $root->contains(null), "\n";
$n = null;
echo (int) $root->contains($n), "\n";
