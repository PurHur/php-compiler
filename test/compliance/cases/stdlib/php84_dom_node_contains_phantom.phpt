--TEST--
stdlib DOMNode::contains — not advertised on PHP 8.2 reference profile (#14535, ext/dom/node.c)
--FILE--
<?php
echo method_exists(DOMNode::class, 'contains') ? "fail\n" : "ok\n";
--EXPECT--
ok
