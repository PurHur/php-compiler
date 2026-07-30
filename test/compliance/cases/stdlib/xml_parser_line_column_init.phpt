--TEST--
stdlib xml_parser_create() line/column start at 1 before parse (#25286, ext/xml/xml.c)
--FILE--
<?php
$parser = xml_parser_create();
echo xml_get_current_line_number($parser), "\n";
echo xml_get_current_column_number($parser), "\n";
echo xml_get_current_byte_index($parser), "\n";
--EXPECT--
1
1
0
