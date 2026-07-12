--TEST--
stdlib Dom\HTMLDocument — not advertised on PHP 8.2 reference profile (#6506, ext/dom/html_document.c)
--FILE--
<?php
echo class_exists('Dom\\HTMLDocument') ? "fail\n" : "ok\n";
?>
--EXPECT--
ok
