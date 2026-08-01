--TEST--
stdlib DOMElement::insertAdjacentHTML — not advertised below PHP 8.5 (#26063, re-#16128, ext/dom/php_dom.stub.php)
--FILE--
<?php
echo method_exists(DOMElement::class, 'insertAdjacentHTML') ? "fail\n" : "ok\n";
--EXPECT--
ok
