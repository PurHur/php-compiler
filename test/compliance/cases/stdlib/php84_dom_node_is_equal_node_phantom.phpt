--TEST--
stdlib DOMNode::isEqualNode — not advertised on PHP 8.2 reference profile (#15195, ext/dom/node.c)
--FILE--
<?php
echo method_exists(DOMNode::class, 'isEqualNode') ? "fail\n" : "ok\n";
--EXPECT--
ok
