<?php

declare(strict_types=1);

// #34383 — AOT xml_error_string / xml_get_current_* (php-src ext/xml/xml.c; peer #27293)
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
echo "DONE\n";
