<?php

declare(strict_types=1);

// #34407 — AOT xml_parser_create_ns + xml_parse_into_struct (php-src ext/xml/xml.c)
$p = xml_parser_create_ns();
$vals = [];
xml_parse_into_struct($p, '<n:r xmlns:n="urn:x"><n:a b="1"/></n:r>', $vals);
echo $vals[0]['tag'], ',', $vals[1]['tag'], ',', $vals[2]['tag'], "\n";

$p2 = xml_parser_create_ns();
xml_parser_set_option($p2, XML_OPTION_CASE_FOLDING, 0);
$vals2 = [];
xml_parse_into_struct($p2, '<n:r xmlns:n="urn:x"><n:a b="1"/></n:r>', $vals2);
echo $vals2[0]['tag'], ',', $vals2[1]['tag'], ',', $vals2[2]['tag'], "\n";

$p3 = xml_parser_create_ns(null, ' ');
$vals3 = [];
xml_parse_into_struct($p3, '<n:r xmlns:n="urn:x"><n:a b="1"/></n:r>', $vals3);
echo $vals3[0]['tag'], ',', $vals3[1]['tag'], ',', $vals3[2]['tag'], "\n";
echo "DONE\n";
