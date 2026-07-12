--TEST--
VM: xml_parse_into_struct() builds values/index arrays (#3494)
--FILE--
<?php
declare(strict_types=1);
$vals = [];
$idx = [];
$status = xml_parse_into_struct(xml_parser_create(), '<a><b/></a>', $vals, $idx);
echo $status, "\n";
echo count($vals), "\n";
echo (int) array_key_exists('B', $idx), "\n";
echo $vals[1]['tag'], ':', $vals[1]['type'], "\n";
--EXPECT--
1
3
1
B:complete
