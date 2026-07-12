--TEST--
libxml_get_last_error() after xml_parse() failure without internal errors (#18146, ext/xml/xml.c)
--FILE--
<?php
libxml_clear_errors();
$parser = xml_parser_create();
xml_parse($parser, '<a><b></a>', true);
$last = libxml_get_last_error();
echo is_object($last) ? get_class($last)."\n" : "notobj\n";
echo $last->code, "\n";
echo str_contains($last->message, 'Opening and ending tag mismatch') ? "message\n" : "nomessage\n";
--EXPECT--
LibXMLError
76
message
