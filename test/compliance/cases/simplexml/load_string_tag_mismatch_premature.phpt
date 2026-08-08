--TEST--
simplexml_load_string() tag mismatch + premature-end libxml errors (#28658, re-#25064, ext/simplexml/sxe.c)
--FILE--
<?php
libxml_use_internal_errors(true);
libxml_clear_errors();
$sx = simplexml_load_string('<a><b></a>');
var_export($sx === false);
echo "\n";
$errors = libxml_get_errors();
echo count($errors), "\n";
echo $errors[0]->code === 76 ? "code76\n" : "nocode76\n";
echo $errors[1]->code === 77 ? "code77\n" : "nocode77\n";
echo $errors[0]->line === 1 ? "line1\n" : "noline1\n";
echo $errors[1]->line === 1 ? "line1b\n" : "noline1b\n";
echo str_contains($errors[0]->message, 'Opening and ending tag mismatch: b line 1 and a') ? "msg76\n" : "nomsg76\n";
echo str_contains($errors[1]->message, 'Premature end of data in tag a line 1') ? "msg77\n" : "nomsg77\n";
--EXPECT--
true
2
code76
code77
line1
line1b
msg76
msg77
