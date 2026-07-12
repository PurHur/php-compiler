--TEST--
libxml_get_last_error() expat message detail after xml_parse() (#18138, ext/xml/xml.c)
--FILE--
<?php
libxml_use_internal_errors(true);
libxml_clear_errors();
$parser = xml_parser_create();
xml_parse($parser, '<a><b></a>', true);
$last = libxml_get_last_error();
echo str_contains($last->message, 'Opening and ending tag mismatch: b line 0 and a') ? "detail\n" : "nodetail\n";
$parser2 = xml_parser_create();
xml_parse($parser2, '<a><b></a>', true);
echo xml_error_string(xml_get_error_code($parser2)), "\n";
--EXPECT--
detail
Mismatched tag
