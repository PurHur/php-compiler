<?php
declare(strict_types=1);

/**
 * AOT DOMNode::cloneNode(true) must seed LiveSlots so firstChild walks work (#32949).
 * php-src ext/dom/node.c php_dom_clone_node → xmlDocCopyNode.
 * Peer: saveXML-only fixture in issue_dom_clonenode_savexml_aot.php (#32355).
 */
$doc = new DOMDocument();
$doc->loadXML('<r><a><b/></a></r>');
$deepRoot = $doc->documentElement->cloneNode(true);
$deepChild = $doc->documentElement->firstChild->cloneNode(true);
$shallow = $doc->documentElement->cloneNode(false);
echo $deepRoot->nodeName, '|', $deepRoot->firstChild->nodeName, '|', $deepRoot->firstChild->firstChild->nodeName, "\n";
echo $deepChild->nodeName, '|', $deepChild->firstChild->nodeName, "\n";
echo $shallow->nodeName, '|', (null === $shallow->firstChild ? 'null' : 'x'), "\n";
