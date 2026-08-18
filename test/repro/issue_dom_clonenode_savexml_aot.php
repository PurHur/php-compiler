<?php
declare(strict_types=1);

/**
 * #32355 — AOT cloneNode(firstChild) must not abort as object::clonenode().
 * php-src ext/dom/node.c php_dom_clone_node → xmlDocCopyNode;
 * saveXML → xmlNodeDump of the detached clone.
 */
$doc = new DOMDocument();
$doc->loadXML('<root><child id="1"><inner/></child></root>');
$child = $doc->documentElement->firstChild;
$deep = $child->cloneNode(true);
$shallow = $child->cloneNode(false);
echo $deep->nodeName, '|', $doc->saveXML($deep), '|', $doc->saveXML($shallow), "END\n";
