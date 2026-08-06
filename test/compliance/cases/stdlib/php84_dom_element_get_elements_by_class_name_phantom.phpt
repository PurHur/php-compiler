--TEST--
stdlib Dom\Element::getElementsByClassName — not advertised below PHP 8.5 (#27593, ext/dom/php_dom.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$has = class_exists('Dom\\Element') && method_exists(Dom\Element::class, 'getElementsByClassName');
echo $has ? "phantom\n" : "ok\n";
$docHas = class_exists('Dom\\Document') && method_exists(Dom\Document::class, 'getElementsByClassName');
echo $docHas ? "doc_phantom\n" : "doc_ok\n";
?>
--EXPECT--
ok
doc_ok
