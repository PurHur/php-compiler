--TEST--
stdlib DOMDocument::loadXML() tag mismatch + premature-end libxml errors (#25064, ext/dom/document.c)
--FILE--
<?php
libxml_use_internal_errors(true);
libxml_clear_errors();
$doc = new DOMDocument();
var_export($doc->loadXML('<r><a></r>'));
echo "\n";
$errors = libxml_get_errors();
echo count($errors), "\n";
echo $errors[0]->code === 76 ? "code76\n" : "nocode76\n";
echo $errors[1]->code === 77 ? "code77\n" : "nocode77\n";
echo $errors[0]->line === 1 ? "line1\n" : "noline1\n";
echo $errors[1]->line === 1 ? "line1b\n" : "noline1b\n";
echo str_contains($errors[0]->message, 'Opening and ending tag mismatch: a line 1 and r') ? "msg76\n" : "nomsg76\n";
echo str_contains($errors[1]->message, 'Premature end of data in tag r line 1') ? "msg77\n" : "nomsg77\n";
--EXPECT--
false
2
code76
code77
line1
line1b
msg76
msg77
