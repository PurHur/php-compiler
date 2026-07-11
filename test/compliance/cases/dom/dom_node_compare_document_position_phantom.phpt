--TEST--
ext/dom DOMNode::compareDocumentPosition — not advertised on PHP 8.2 reference profile (#18092, ext/dom/node.c)
--FILE--
<?php
echo method_exists(DOMNode::class, 'compareDocumentPosition') ? "fail\n" : "ok\n";
--EXPECT--
ok
