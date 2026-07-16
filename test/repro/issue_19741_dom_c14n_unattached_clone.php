<?php
/** Repro #19741 — DOMNode::C14N() on unattached cloneNode is empty (php-src-strict). */
$d = new DOMDocument();
$d->loadXML('<r><a x="1">t</a></r>');
$root = $d->documentElement;
echo 'root_c14n=[', $root->C14N(), "]\n";
$clone = $root->cloneNode(true);
echo 'clone_parent=', $clone->parentNode ? 'set' : 'null', "\n";
echo 'clone_c14n=[', $clone->C14N(), "]\n";
$d->documentElement->appendChild($clone);
echo 'attached_c14n=[', $clone->C14N(), "]\n";
