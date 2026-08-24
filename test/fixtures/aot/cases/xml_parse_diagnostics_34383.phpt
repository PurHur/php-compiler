--TEST--
AOT xml_parse diagnostics — error_string + get_current_* (#34383)
--FILE--
<?php
$p = xml_parser_create();
xml_parse($p, '<a><b></a>', true);
echo xml_get_error_code($p), "\n";
echo xml_error_string(xml_get_error_code($p)), "\n";
echo xml_get_current_line_number($p), "\n";
echo xml_get_current_column_number($p), "\n";
echo xml_get_current_byte_index($p), "\n";
$p2 = xml_parser_create();
xml_parse($p2, '<root/>', true);
echo xml_error_string(xml_get_error_code($p2)), "\n";
echo xml_get_current_line_number($p2), "\n";
--EXPECT--
76
Mismatched tag
1
11
10
No error
1
