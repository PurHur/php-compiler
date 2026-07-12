--TEST--
stdlib DOMDocument::loadXML() malformed XML — libxml_get_errors() count matches Zend (#18332, ext/dom/document.c)
--FILE--
<?php
libxml_use_internal_errors(true);
libxml_clear_errors();
$doc = new DOMDocument();
$doc->loadXML('<root><unclosed');
$errors = libxml_get_errors();
echo count($errors), "\n";
echo $errors[0]->code === 73 ? "code73\n" : "nocode73\n";
echo $errors[1]->code === 77 ? "code77\n" : "nocode77\n";
echo str_contains($errors[1]->message, 'Premature end of data in tag root') ? "msg77\n" : "nomsg77\n";
--EXPECT--
2
code73
code77
msg77
