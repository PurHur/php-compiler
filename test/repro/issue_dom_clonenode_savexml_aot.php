<?php
declare(strict_types=1);

/**
 * AOT DOMNode::cloneNode(true) must not abort as object::clonenode() (#32355).
 * php-src ext/dom/node.c php_dom_clone_node → xmlDocCopyNode;
 * saveXML → xmlNodeDump of the clone (not in the tree).
 */
$doc = new DOMDocument();
$doc->loadXML('<root><child id="1"><inner/></child></root>');
$child = $doc->documentElement->firstChild;
$deep = $child->cloneNode(true);
$shallow = $child->cloneNode(false);
echo $deep->nodeName, '|', $doc->saveXML($deep), '|', $doc->saveXML($shallow), "END\n";
