--TEST--
stdlib DOMElement::insertAdjacentText — not advertised on PHP 8.2 reference profile (#16914, ext/dom/element.c)
--FILE--
<?php
echo method_exists(DOMElement::class, 'insertAdjacentText') ? "fail\n" : "ok\n";
--EXPECT--
ok
