--TEST--
stdlib DOMElement::toggleAttribute — not advertised on PHP 8.2 reference profile (#16824, ext/dom/element.c)
--FILE--
<?php
echo method_exists(DOMElement::class, 'toggleAttribute') ? "fail\n" : "ok\n";
--EXPECT--
ok
