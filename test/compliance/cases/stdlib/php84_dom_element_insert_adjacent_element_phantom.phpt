--TEST--
stdlib DOMElement::insertAdjacentElement — not advertised on PHP 8.2 reference profile (#16865, ext/dom/php_dom.c)
--FILE--
<?php
echo method_exists(DOMElement::class, 'insertAdjacentElement') ? "fail\n" : "ok\n";
--EXPECT--
ok
