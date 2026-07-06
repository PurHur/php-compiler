--TEST--
stdlib DOMElement::getInnerHTML()/getOuterHTML() — not advertised on PHP 8.2 reference profile (#16916, ext/dom/inner_html_mixin.c)
--FILE--
<?php
echo method_exists(DOMElement::class, 'getInnerHTML') ? "fail\n" : "ok\n";
echo method_exists(DOMElement::class, 'getOuterHTML') ? "fail\n" : "ok\n";
?>
--EXPECT--
ok
ok
