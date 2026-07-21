<?php
/**
 * #21766 — detached DOMElement::getRootNode() returns self, not ownerDocument (php-src ext/dom/node.c).
 */
declare(strict_types=1);

$doc = new DOMDocument();
$detached = $doc->createElement('x');
echo ($detached->getRootNode() === $detached) ? "detached_self\n" : "detached_other\n";
echo ($detached->getRootNode() === $doc) ? "detached_doc_bad\n" : "detached_not_doc\n";
