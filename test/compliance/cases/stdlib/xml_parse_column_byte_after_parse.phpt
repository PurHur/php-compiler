--TEST--
stdlib xml_parse() column/byte after parse match Expat (#25817, ext/xml/xml.c)
--FILE--
<?php
$parser = xml_parser_create();
echo xml_get_current_line_number($parser), ' ',
    xml_get_current_column_number($parser), ' ',
    xml_get_current_byte_index($parser), "\n";
xml_parse($parser, '<r>', true);
echo xml_get_error_code($parser), ' ',
    xml_get_current_line_number($parser), ' ',
    xml_get_current_column_number($parser), ' ',
    xml_get_current_byte_index($parser), "\n";
xml_parser_free($parser);

$parser = xml_parser_create();
xml_parse($parser, "<root>\n<a/>\n</root>", true);
echo xml_get_error_code($parser), ' ',
    xml_get_current_line_number($parser), ' ',
    xml_get_current_column_number($parser), ' ',
    xml_get_current_byte_index($parser), "\n";
xml_parser_free($parser);

$parser = xml_parser_create();
xml_parse($parser, "<a/>\n", true);
echo xml_get_error_code($parser), ' ',
    xml_get_current_line_number($parser), ' ',
    xml_get_current_column_number($parser), ' ',
    xml_get_current_byte_index($parser), "\n";
--EXPECT--
1 1 0
5 1 1 0
0 3 8 19
0 2 1 5
