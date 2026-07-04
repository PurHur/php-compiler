--TEST--
stdlib DOMElement::insertAdjacentHTML — not advertised on PHP 8.2 reference profile (#16128, ext/dom/dom_element.c)
--FILE--
<?php
echo method_exists(DOMElement::class, 'insertAdjacentHTML') ? "fail\n" : "ok\n";
--EXPECT--
ok
