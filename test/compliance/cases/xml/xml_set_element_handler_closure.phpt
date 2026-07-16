--TEST--
ext/xml xml_set_element_handler() — Closure callbacks via variables (#19343, #19683)
--FILE--
<?php
$p = xml_parser_create();
xml_parser_set_option($p, XML_OPTION_CASE_FOLDING, 0);
$start = function ($parser, $name, $attrs) { echo "S:$name\n"; };
$end = function ($parser, $name) { echo "E:$name\n"; };
xml_set_element_handler($p, $start, $end);
xml_parse($p, '<root><a/></root>', true);
xml_parser_free($p);
echo "done\n";
--EXPECT--
S:root
S:a
E:a
E:root
done
