--TEST--
stdlib xml_parser_free() and xml_parse() self-closing element (#11987, ext/xml/xml.c)
--FILE--
<?php
$parser = xml_parser_create();
echo (int) function_exists('xml_parser_free'), "\n";
echo (int) xml_parse($parser, '<root/>', true), "\n";
echo (int) xml_parser_free($parser), "\n";
?>
--EXPECT--
1
1
1
