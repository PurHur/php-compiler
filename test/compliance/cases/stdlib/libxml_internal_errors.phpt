--TEST--
stdlib libxml internal error buffer with malformed xml_parse() input (#6058)
--FILE--
<?php
echo (int) function_exists('libxml_use_internal_errors');
echo (int) class_exists('LibXMLError');
echo (int) extension_loaded('libxml');

$prev = libxml_use_internal_errors(true);
echo (int) $prev, "\n";

$parser = xml_parser_create();
$ok = xml_parse($parser, '<broken', true);
echo (int) $ok, "\n";

$errors = libxml_get_errors();
echo count($errors), "\n";
echo $errors[0]->level, "\n";
echo '' !== $errors[0]->message ? "msg\n" : "nomsg\n";
echo get_class($errors[0]), "\n";

libxml_clear_errors();
echo count(libxml_get_errors()), "\n";

$prev2 = libxml_use_internal_errors(false);
echo (int) $prev2, "\n";
--EXPECT--
1110
0
1
3
msg
LibXMLError
0
1
