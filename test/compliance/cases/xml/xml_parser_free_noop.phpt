--TEST--
xml_parser_free() is a no-op since PHP 8.0 — parser stays usable (#22813, ext/xml/xml.c)
--FILE--
<?php
$p = xml_parser_create();
echo (int) xml_parser_free($p), "\n";
echo (int) xml_parse($p, '<a/>', true), "\n";
echo get_debug_type($p), "\n";
echo (int) xml_parser_free($p), "\n";
?>
--EXPECT--
1
1
XMLParser
1
