<?php
declare(strict_types=1);

$impl = new DOMImplementation();

$empty = $impl->createDocument();
echo 'empty-docElem-null=', var_export($empty->documentElement, true), "\n";

$root = $impl->createDocument(null, 'root');
echo 'two-arg-root=', $root->documentElement->nodeName, "\n";

$ns = $impl->createDocument('http://example.com', 'item');
echo 'two-arg-ns=', $ns->documentElement->namespaceURI, ':', $ns->documentElement->nodeName, "\n";
