--TEST--
stdlib Dom\XMLDocument — not advertised on PHP 8.2 reference profile (#19581, ext/dom/xml_document.c)
--FILE--
<?php
echo class_exists('Dom\\XMLDocument') ? "fail\n" : "ok\n";
?>
--EXPECT--
ok
