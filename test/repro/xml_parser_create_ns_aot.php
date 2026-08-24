<?php

declare(strict_types=1);

// #34407 — AOT xml_parser_create_ns + xml_parse_into_struct (php-src ext/xml/xml.c)
function tags(array $vals): string
{
    $out = [];
    foreach ($vals as $row) {
        $out[] = $row['tag'];
    }

    return implode(',', $out);
}

$p = xml_parser_create_ns();
$vals = [];
xml_parse_into_struct($p, '<n:r xmlns:n="urn:x"><n:a b="1"/></n:r>', $vals);
echo tags($vals), "\n";

$p2 = xml_parser_create_ns(null, ' ');
$vals2 = [];
xml_parse_into_struct($p2, '<n:r xmlns:n="urn:x"><n:a b="1"/></n:r>', $vals2);
echo tags($vals2), "\n";

$p3 = xml_parser_create_ns();
xml_parser_set_option($p3, XML_OPTION_CASE_FOLDING, 0);
$vals3 = [];
xml_parse_into_struct($p3, '<n:r xmlns:n="urn:x"><n:a b="1"/></n:r>', $vals3);
echo tags($vals3), "\n";
