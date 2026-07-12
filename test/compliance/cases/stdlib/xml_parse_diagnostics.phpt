--TEST--
stdlib xml_parse() diagnostics — error string and position (#18120, ext/xml/xml.c)
--FILE--
<?php
$parser = xml_parser_create();
xml_parse($parser, '<a><b></a>', true);
echo xml_get_error_code($parser), "\n";
echo xml_error_string(xml_get_error_code($parser)), "\n";
echo xml_get_current_line_number($parser), "\n";
echo xml_get_current_column_number($parser), "\n";
echo xml_get_current_byte_index($parser), "\n";
--EXPECT--
76
Mismatched tag
1
11
10
