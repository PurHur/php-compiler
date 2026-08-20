<?php
declare(strict_types=1);

/**
 * AOT DOMNode::cloneNode(true) must seed firstChild LiveSlots (#32949 / re-#32355).
 * php-src ext/dom/node.c php_dom_clone_node → xmlDocCopyNode.
 */
$d = new DOMDocument();
$d->loadXML('<r><a><b/></a></r>');
$c = $d->documentElement->cloneNode(true);
$s = $d->documentElement->cloneNode(false);
echo $c->nodeName, '|', $c->firstChild->nodeName, '|', $c->firstChild->firstChild->nodeName;
echo '|', null === $s->firstChild ? 'null' : $s->firstChild->nodeName, "\n";
