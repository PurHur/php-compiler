--TEST--
stdlib Dom\HTMLDocument::createFromString() — PHP 8.4 living DOM namespace (#6506, ext/dom/html_document.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo 'HTMLDocument: ', (int) class_exists('Dom\\HTMLDocument'), "\n";
echo 'XMLDocument: ', (int) class_exists('Dom\\XMLDocument'), "\n";
echo 'Document: ', (int) class_exists('Dom\\Document'), "\n";
echo 'Node: ', (int) class_exists('Dom\\Node'), "\n";
echo 'Element: ', (int) class_exists('Dom\\Element'), "\n";
$doc = Dom\HTMLDocument::createFromString('<p>hi</p>');
echo $doc->body->textContent, "\n";
$empty = Dom\HTMLDocument::createEmpty();
echo ($empty->body !== null ? 'empty_body' : 'empty_fail'), "\n";
?>
--EXPECT--
HTMLDocument: 1
XMLDocument: 1
Document: 1
Node: 1
Element: 1
hi
empty_body
