--TEST--
AOT xml_parser_create + xml_parse_into_struct builds values/index (#34378)
--FILE--
<?php
$p = xml_parser_create();
$vals = [];
$idx = [];
$status = xml_parse_into_struct($p, '<a><b/></a>', $vals, $idx);
echo $status, "\n";
echo count($vals), "\n";
echo (int) array_key_exists('B', $idx), "\n";
echo $vals[1]['tag'], ':', $vals[1]['type'], "\n";
--EXPECT--
1
3
1
B:complete
