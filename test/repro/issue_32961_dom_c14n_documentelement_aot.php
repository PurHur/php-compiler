<?php
declare(strict_types=1);

/**
 * AOT documentElement->C14N() must bind DOMNode::C14N (#32961 / peer #32957).
 * php-src ext/dom/node.c dom_node_c14n.
 */
$d = new DOMDocument();
$d->loadXML('<r a="1"/>');
echo $d->documentElement->C14N(), "\n";
