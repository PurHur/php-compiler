--TEST--
stdlib DOMNode::getRootNode — not advertised on PHP 8.2 reference profile (#14599, ext/dom/node.c)
--FILE--
<?php
echo method_exists(DOMNode::class, 'getRootNode') ? "fail\n" : "ok\n";
--EXPECT--
ok
