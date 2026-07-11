--TEST--
stdlib DOMNode::replaceChildren — not advertised on PHP 8.2 reference profile (#17119, ext/dom/parentnode.c)
--FILE--
<?php
echo method_exists(DOMNode::class, 'replaceChildren') ? "fail\n" : "ok\n";
--EXPECT--
ok
